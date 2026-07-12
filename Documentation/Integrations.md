# VidPly Integrations

How to integrate VidPly with dynamic frontend frameworks, site packages, Content Security Policy, and structured data.

---

## Dynamic content (Vue, Swiper, AJAX)

VidPly initializes on `DOMContentLoaded`. If your site injects player markup **after** that (Vue `v-html`, Swiper slide clones, Turbo, etc.), call the public scan API so new `[data-vidply-init]` / `[data-playlist]` elements are picked up.

### Re-scan after HTML injection

`PlaylistInit.js` and `PrivacyLayer.js` both listen for:

```javascript
document.dispatchEvent(new CustomEvent('mpc:dynamic-content:ready', {
  detail: { root: containerElement }  // Element or Document fragment to scan
}));
```

Or call directly:

```javascript
window.VidPlyInit.scan(containerElement);
```

- **`containerElement`** — Root of the newly inserted HTML (a slide, modal, or `document` for a full rescan).
- Duplicate Swiper slides (`.swiper-slide-duplicate`) are **skipped** by default so cloned DOM does not double-init players.

### Pause players on inactive slides

When switching Swiper slides (or similar), pause players that are no longer visible:

```javascript
window.VidPlyInit.pauseOutside(activeSlideElement);
```

This pauses every initialized player whose `<video>` / wrapper is **outside** the given container.

### Late-loading scripts

If VidPly assets load after Vue has already rendered slides, `PlaylistInit.js` runs a catch-up pass when the module executes (`catchUpVueContainers`). Still dispatch `mpc:dynamic-content:ready` after each dynamic update for reliability.

### Debug

Set `window.VIDPLY_DEBUG = true` before VidPly scripts load to keep all VidPly console logs visible.

---

## Theme sync (dark / light)

Extension Configuration (`Admin → Settings → Extension Configuration → mpc_vidply`):

| Setting | Effect |
|---------|--------|
| `theme` | Default player theme: `dark` or `light` |
| `themeSyncEnabled` | When enabled, adds `data-vidply-theme-sync="1"` on the player wrapper |

When theme sync is on, the player follows page light/dark mode (body class changes and custom events).

Programmatic API (after `PlaylistInit.js` loads):

```javascript
window.VidPlyTheme.setTheme('dark');   // or 'light'
window.VidPlyTheme.getPlayers();       // alive Player instances
window.VidPlyTheme.detectPageTheme();  // read current page theme
```

See [Settings Architecture → Extension Configuration](SettingsArchitecture.md#extension-configuration-global).

---

## Content Security Policy (CSP)

VidPly registers CSP mutations in `Configuration/ContentSecurityPolicies.php` and extends `connect-src` via `PolicyMutatedEvent` for streaming CDNs.

### Streaming (HLS / DASH)

Required for adaptive streaming in modern browsers:

- `media-src`: `blob:`, `data:`, `https:` (and `'self'`)
- `worker-src`: `blob:`
- `connect-src`: `blob:`, `data:`, `https:` (segment/manifest fetches)

Details and troubleshooting: [HLS-Implementation.md](HLS-Implementation.md).

### Vendored scripts

`mpc-vidply` ships **hls.js** and **dash.js** under `Resources/Public/JavaScript/`. No CDN script origin is required for the TYPO3 extension (since v1.2.6).

### mp-core and inline nonces

On sites using **mp-core**, inline `<script>` / `<style>` blocks may carry CSP nonces on `script-src` / `style-src`. VidPly ensures `nonceProxy` is preserved on `script-src-elem` / `style-src-elem` so theme bootstrap and structured-data blocks are not blocked when VidPly is present (v1.2.7).

If you customize CSP in your site package, ensure streaming directives above are not overwritten without merging VidPly's requirements.

---

## Structured data (JSON-LD) with mp-core

VidPly emits `schema.org` `VideoObject` / `AudioObject` (or `ItemList` on multi-video pages) from `page.headerData.2042`.

### Standalone (mpc-vidply only)

A dedicated `<script type="application/ld+json">` is rendered when media is present. Disable via site setting:

```yaml
# config/sites/<site>/config.yaml
settings:
  structuredDataEnabled: false
```

If your site has no Site Set defining this key, add it manually — mpc-vidply reads `structuredDataEnabled` with default `true`.

### With mp-core installed

When **mp-core** is loaded and produces a JSON-LD `@graph`, VidPly **merges** its video node into that graph and sets `WebPage.mainEntity` (single script tag). If mp-core yields no usable graph, VidPly falls back to standalone output.

On mp-core sites, structured data may also require **`seo.schema.enabled`** (both toggles must be true for mp-core's global JSON-LD path). VidPly's own processor still respects `structuredDataEnabled`.

Full field mapping and watch-URL resolution: [Developers Quickstart → Structured data](Developers-Quickstart.md#structured-data-json-ld).

---

## Media Session API

The bundled VidPly player registers **Media Session** handlers (lock screen / headset / notification controls and now-playing metadata). On pages with multiple players, the API targets the instance that is actually playing (v1.2.8). No TYPO3 configuration is required.

---

## Progressive enhancement (no JavaScript)

Local video/audio content elements render semantic `<video controls>` / `<audio controls>` with `<source>` and `<track>` elements. Without JavaScript, browsers show native HTML5 controls; VidPly enhances the same markup when scripts load. External services (YouTube/Vimeo/SoundCloud) require consent JavaScript to load embeds.

---

## Site package template overrides

Point VidPly content elements at your frame/header partials:

```typoscript
lib.mpcVidplyContentElement.layoutRootPaths.10 = {$styles.templates.layoutRootPath}
lib.mpcVidplyContentElement.partialRootPaths.10 = {$styles.templates.partialRootPath}
```

Override player partials:

```typoscript
tt_content.mpc_vidply {
    partialRootPaths.20 = EXT:your_sitepackage/Resources/Private/Partials/
}
```

See [Partials.md](Partials.md) for file names and `data-*` hooks required by `Listview.js`.

---

## SoundCloud renderer mode (advanced)

Default TYPO3 behaviour: SoundCloud uses the **privacy-layer iframe path** (~7 KB JS). To drive SoundCloud through VidPly's unified control bar, override `PrivacyLayer.html` and set `$needsVidPlay = true` for SoundCloud in a custom processor. Step-by-step: [PrivacyLayer.md → Switch SoundCloud to Renderer Mode](PrivacyLayer.md#switch-soundcloud-to-renderer-mode-advanced).
