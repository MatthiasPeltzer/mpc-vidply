# Developer Quickstart

Quick reference for developers working with VidPly. For detailed docs, see individual files.

---

## 🚀 Installation

```bash
composer require mpc/mpc-vidply
```

Then:
1. **Database Update** → Admin Tools → Maintenance → Analyze Database Structure
2. **Include Site Set** → Site Management → Sites → Your Site → Sets → Add `mpc/mpc-vidply`
3. **Clear Caches**

**Requirements:** TYPO3 Core, Fluid, and Extbase only — `fluid_styled_content` is **not** required. The extension ships its own `lib.mpcVidplyContentElement` renderer and Default layout (frame wrapper + `tt_content` header/subheader).

**Optional theming:** If your site package or `fluid_styled_content` ships custom frame/header partials, you can point VidPly at them:

```typoscript
lib.mpcVidplyContentElement.layoutRootPaths.10 = {$styles.templates.layoutRootPath}
lib.mpcVidplyContentElement.partialRootPaths.10 = {$styles.templates.partialRootPath}
```

---

## 📁 Directory Structure

```
mpc_vidply/
├── Classes/
│   ├── Backend/
│   │   ├── Form/FieldWizard/     # MediaTypeFilterWizard
│   │   └── Preview/              # VidPlyPreviewRenderer
│   └── DataProcessing/           # VidPly, Listview, Detail processors
├── Configuration/
│   ├── Sets/mpc-vidply/          # Site Set (TYPO3 13.4+)
│   ├── TCA/
│   │   ├── tx_mpcvidply_media.php # Media record TCA
│   │   └── Overrides/
│   │       └── tt_content.php    # Content element TCA
│   ├── TypoScript/
│   │   └── Helper/ContentElement.typoscript  # lib.mpcVidplyContentElement
│   ├── ContentSecurityPolicies.php
│   ├── Icons.php
│   ├── JavaScriptModules.php
│   └── Services.yaml
├── Resources/
│   ├── Private/
│   │   ├── Language/             # XLF translations (en, de)
│   │   ├── Partials/VidPly/      # Modular template partials
│   │   ├── Layouts/              # Default content element layout
│   │   └── Templates/            # Main VidPly.html template
│   └── Public/
│       ├── Css/vidply.min.css
│       ├── Icons/
│       └── JavaScript/
│           ├── hls.min.js        # HLS streaming (loaded only when needed)
│           ├── dash.all.min.js   # DASH streaming (loaded only when needed)
│           ├── PlaylistInit.js   # Playlist logic
│           ├── PrivacyLayer.js   # GDPR consent (YouTube/Vimeo/SoundCloud)
│           └── vidply/           # Core player (compiled from TypeScript, ESM, code-split)
└── Documentation/
    ├── AssetLoading.md
    ├── HLS-Implementation.md
    ├── Partials.md
    ├── PrivacyLayer.md
    └── SettingsArchitecture.md
```

---

## ⚡ Site Set

Enable in `config/sites/<site>/config.yaml`:

```yaml
dependencies:
  - mpc/mpc-vidply
```

Or via Backend: Site Management → Sites → Sets → Add VidPly.

---

## 📦 Database Tables

### `tx_mpcvidply_media`

Media library records with types: `video`, `audio`, `youtube`, `vimeo`, `soundcloud`

**Key columns:**

| Column | Type | Description |
|--------|------|-------------|
| `media_type` | string | Type discriminator |
| `media_file` | file | File references (video/audio, HLS/DASH, YouTube/Vimeo/SoundCloud via FAL online-media helpers) |
| `title` | string | Display title |
| `artist` | string | Creator name |
| `poster` | file | Thumbnail image |
| `captions` | file | WebVTT / SubRip caption files (SRT auto-converts to VTT on save) |
| `chapters` | file | WebVTT / SubRip chapter files (SRT auto-converts to VTT on save) |
| `audio_description` | file | Described video (MP4/WebM) for source swap |
| `audio_description_mode` | select | `auto`, `swap`, or `vtt_speech` — see Audio Description modes below |
| `audio_description_duration` | int | Described version duration in seconds (playlist UI) |
| `sign_language` | file | Sign language overlay |
| `enable_transcript` | bool | Show transcript panel |

**Audio description — player option mapping**

`VidPlyProcessor` maps media fields to VidPly `data-vidply-options`:

| Media field | Player option |
|-------------|---------------|
| `audio_description` (first file) | `audioDescriptionSrc` |
| `audio_description_mode` | `audioDescriptionMode` (`auto` \| `swap` \| `vtt_speech`) |
| `audio_description_duration` | `audioDescriptionDuration` |
| Captions with `tx_track_kind = descriptions` | `<track kind="descriptions">` |

**Fallback hierarchy** when `audioDescriptionMode` is `auto`:

1. Described video swap if `audio_description` is set
2. Else VTT speech if a descriptions VTT track exists
3. Else AD button hidden (no spoken AD)

The AD button is enabled when either a described video or a descriptions VTT track is present.

### SRT → WebVTT conversion

VidPly playback and transcripts require **WebVTT**. The extension accepts **SubRip (`.srt`)** uploads for captions, chapters, and audio-description alternate tracks (`tx_desc_src_file`).

| Trigger | Behavior |
|---------|----------|
| Save `tx_mpcvidply_media` in the backend | Linked `.srt` files are converted in place to `.vtt`; editors receive a flash message |
| Upgrade wizard (no shell) | **Admin Tools → Upgrade → Upgrade Wizards → VidPly: Convert SRT caption files to WebVTT** |
| CLI (optional, DDEV/CI) | `vendor/bin/typo3 mpc-vidply:convert-srt-to-vtt [--dry-run] [--limit=N]` |

Implementation: `SrtToVttConverter`, `SrtCaptionMigrationService`, `SrtCaptionConversionHook`, `ConvertSrtCaptionsUpgradeWizard`.

### `tx_mpcvidply_content_media_mm`

MM relation between `tt_content` and `tx_mpcvidply_media`.

### `tx_mpcvidply_privacy_settings`

Site-wide privacy layer configuration for external services (YouTube, Vimeo, SoundCloud).

**Key columns:**

| Column | Type | Description |
|--------|------|-------------|
| `youtube_headline` | varchar(255) | Optional headline |
| `youtube_intro_text` | text | Intro text before link |
| `youtube_outro_text` | text | Outro text after link |
| `youtube_policy_link` | varchar(255) | Privacy policy URL |
| `youtube_link_text` | varchar(255) | Link text |
| `youtube_button_label` | varchar(255) | Button aria-label |
| `vimeo_*` | (same fields) | Vimeo settings |
| `soundcloud_*` | (same fields) | SoundCloud settings |
| `sys_language_uid` | int | Language ID (multilingual) |

---

## 🎬 Content Element

### CType: `mpc_vidply`

**TCA fields:**

| Field | Type | Description |
|-------|------|-------------|
| `tx_mpcvidply_media_items` | group | Media record selection (MM) |
| `tx_mpcvidply_options` | check | Bitmask options |
| `tx_mpcvidply_volume` | decimal | Default volume (0-1) |
| `tx_mpcvidply_playback_speed` | decimal | Default speed (0.25-2.0) |
| `tx_mpcvidply_language` | select | Force UI language |

### Options Bitmask

```php
const AUTOPLAY        = 1;    // Auto-start playback
const LOOP            = 2;    // Loop content
const MUTED           = 4;    // Start muted
const CONTROLS        = 8;    // Show controls
const CAPTIONS_DEFAULT = 16;  // Captions on by default
const KEYBOARD        = 64;   // Enable keyboard shortcuts
const AUTO_ADVANCE    = 256;  // Auto-play next in playlist

// Default: 328 = CONTROLS + KEYBOARD + AUTO_ADVANCE
```

**Notes:**
- Responsive sizing is always enabled (no toggle).
- Transcript is controlled per media record (`tx_mpcvidply_media.enable_transcript`).

**Check option in Fluid:**

```html
<f:if condition="{data.tx_mpcvidply_options} % 16 >= 8">
    <!-- Controls enabled -->
</f:if>
```

---

## 🔧 Data Processing

### VidPlyProcessor

Processes media items for frontend rendering.

**TypoScript:**

```typoscript
tt_content.mpc_vidply =< lib.mpcVidplyContentElement
tt_content.mpc_vidply {
    templateName = VidPly
    dataProcessing {
        10 = Mpc\MpcVidply\DataProcessing\VidPlyProcessor
    }
}
```

**Available in template:**

```html
{mediaItems}           <!-- Array of processed media -->
{isPlaylist}           <!-- true if 2+ items -->
{hasLocalMedia}        <!-- Has video/audio (including HLS/DASH sources) -->
{hasExternalService}   <!-- Has YouTube/Vimeo/SoundCloud -->
{playerOptions}        <!-- Decoded options array -->
{privacySettings}      <!-- Privacy layer settings per service -->
```

### Listview & detail (Mediathek-style CEs)

- **`ListviewProcessor`** — `mpc_vidply_listview`; builds `listview.rows` with
  cards, pagination flags, sort defaults, etc. See
  `Documentation/Listview.md` (routing, i18n, editor fields).
- **`DetailProcessor`** — `mpc_vidply_detail`; builds `detail.*` and player
  data for the detail page. Template: `Detail.html` + `Listview` CSS when
  shared. The related shelf is loaded only if `tt_content.tx_mpcvidply_show_related`
  is enabled (default `1`); when off, `detail.related` is empty.

**Templates / partials:** `Templates/Listview.html`, `Partials/Listview/*`.

---

## 🎨 Template Structure

### Main Template

`Resources/Private/Templates/VidPly.html`

### Partials

| Partial | Purpose |
|---------|---------|
| `VidPly/Assets.html` | Conditional asset registration |
| `VidPly/VideoSources.html` | `<source>` elements for video |
| `VidPly/AudioSources.html` | `<source>` elements for audio |
| `VidPly/Tracks.html` | Caption/chapter `<track>` elements |
| `VidPly/MetadataScripts.html` | JSON-LD accessibility metadata |
| `VidPly/PrivacyLayer.html` | GDPR consent overlay |

### Customization

Override templates in your sitepackage:

```typoscript
tt_content.mpc_vidply {
    templateRootPaths.20 = EXT:my_sitepackage/Resources/Private/Templates/
    partialRootPaths.20 = EXT:my_sitepackage/Resources/Private/Partials/
}
```

---

## 📜 JavaScript Architecture

### Conditional Loading

VidPly only loads JavaScript needed for current media types:

| Scenario | Assets Loaded | Size |
|----------|---------------|------|
| YouTube/Vimeo/SoundCloud only | PrivacyLayer.js | ~5KB |
| Local video/audio | `vidply/vidply.esm.min.js` (+ chunks) + PlaylistInit.js | ~180KB |
| HLS streaming | + hls.min.js | +60KB |
| DASH streaming | + dash.all.min.js | +200KB |
| Playlist (2+ items) | + PlaylistInit.js | +5KB |

**Up to 97% reduction** for external-only content.

### Files

| File | Purpose |
|------|---------|
| `PrivacyLayer.js` | GDPR consent for external services (YouTube / Vimeo / SoundCloud) |
| `PlaylistInit.js` | Playlist UI and navigation |
| `hls.min.js` | hls.js **1.6.16** for adaptive HLS streaming (Chrome / Firefox / Edge / desktop Safari) |
| `dash.all.min.js` | dash.js **5.2.0** (modern UMD) for MPEG-DASH streaming |
| `vidply/*.js` | Core player (compiled TypeScript → ESM, code-split, includes SoundCloud renderer + buffering spinner + optional download button) |

### Player Initialization

```javascript
// This is roughly what `PlaylistInit.js` does internally:
import { Player, PlaylistManager } from './vidply/vidply.esm.min.js';

const player = new Player('#my-player', {
    controls: true,
    keyboard: true,
    responsive: true,
});

// Optional: attach a playlist
const playlist = new PlaylistManager(player, { autoAdvance: true });
```

---

## 🔒 Privacy Layer

### Implementation

External services (YouTube, Vimeo, SoundCloud) show consent overlay:

1. `PrivacySettingsService` fetches settings from `tx_mpcvidply_privacy_settings` table
2. Template renders poster + play button (no iframe) with database settings
3. `PrivacyLayer.js` / `PlaylistInit.js` handles click
4. On consent: Creates iframe, loads service player
5. No tracking until user explicitly clicks play

### Privacy Settings Service

`Classes/Service/PrivacySettingsService.php` provides:

```php
$privacySettings = $privacySettingsService->getSettingsForService('youtube', $languageId);
// Returns: ['headline', 'intro_text', 'outro_text', 'policy_link', 'link_text', 'button_label']
```

Settings fall back to language file translations if database fields are empty.

### Customize Privacy Notice

**Recommended:** Configure via backend (List Module → Privacy Layer Settings)

**Or override template:** `Partials/VidPly/PrivacyLayer.html`

```html
<div class="vidply-privacy-layer" 
     data-service="{service}" 
     data-embed-url="{embedUrl}">
    <f:if condition="{privacySettings.headline}">
        <h3>{privacySettings.headline}</h3>
    </f:if>
    <img src="{poster}" alt="{title}" />
    <button class="vidply-privacy-play">
        {privacySettings.button_label}
    </button>
    <p class="vidply-privacy-notice">
        {privacySettings.intro_text}
        <a href="{privacySettings.policy_link}">{privacySettings.link_text}</a>
        {privacySettings.outro_text}
    </p>
</div>
```

---

## 🌐 HLS & DASH Streaming

HLS and DASH are not separate media types. Streaming sources (.m3u8 / .mpd) are added as files within the **Video** or **Audio** media type alongside progressive fallbacks.

### How It Works

**HLS:**
1. Detects `.m3u8` source in media file references (by MIME type or extension)
2. Includes `hls.min.js` (only when needed) — the VidPly bundle uses it on Chrome / Firefox / Edge / desktop Safari for full feature parity
3. On iOS / iPadOS Safari (where MSE is unavailable), VidPly automatically falls back to **native HLS** and bridges the browser's `TextTrack` API into the VidPly captions, transcript and quality menus — no separate code path required
4. Provides quality switching UI
5. Embedded captions from HLS manifest used by default; local VTT files override
6. The HLS renderer also reacts to `Hls.Events.SUBTITLE_FRAG_PROCESSED`, so the interactive transcript stays in sync as new subtitle fragments are loaded for long / live streams

**DASH:**
1. Detects `.mpd` source in media file references (by MIME type or extension)
2. Includes `dash.all.min.js` (only when needed)
3. Initializes dash.js on video/audio element
4. Provides adaptive quality, TTML and WebVTT subtitle support
5. Embedded subtitles from DASH manifest used by default; local VTT files override

**Source priority:** DASH → HLS → progressive (MP4/WebM/MP3/OGG)

### CSP Configuration

`Configuration/ContentSecurityPolicies.php` whitelists streaming domains and the schemes used by `hls.js` / `dash.js`.

Add custom domains:

```php
return [
    'default-src' => ["'self'"],
    'media-src' => ["'self'", 'blob:', 'data:', 'https://your-cdn.com'],
    'connect-src' => ["'self'", 'https://your-cdn.com'],
];
```

> `blob:` is required because `hls.js` / `dash.js` set a `blob:` URL on `<video>.src` while playing. `data:` is required because some HLS variants embed init segments / subtitles inline as data URIs.

---

## 🛠️ Extending VidPly

### Add Custom Media Type

1. **Extend TCA** (`Configuration/TCA/Overrides/tx_mpcvidply_media.php`):

```php
$GLOBALS['TCA']['tx_mpcvidply_media']['columns']['media_type']['config']['items'][] = [
    'label' => 'My Service',
    'value' => 'myservice',
];

$GLOBALS['TCA']['tx_mpcvidply_media']['types']['myservice'] = [
    'showitem' => '...',
];
```

2. **Update DataProcessor** to handle new type

3. **Create Partial** for rendering

### Custom Player Options

Add to `tt_content.php`:

```php
$GLOBALS['TCA']['tt_content']['columns']['tx_mpcvidply_options']['config']['items'][] = [
    'label' => 'My Option',
    'value' => 512,  // Next power of 2
];
```

---

## 🐛 Debugging

### Common Issues

| Issue | Solution |
|-------|----------|
| Media not showing | Check `tx_mpcvidply_media` record not hidden |
| MM relation broken | Check `tx_mpcvidply_content_media_mm` table |
| JS not loading | Check browser console; verify TypoScript |
| HLS not working | Check CORS headers on .m3u8; verify source file is in media record |
| DASH not working | Check CORS headers on .mpd; verify source file is in media record; verify dash.js loads |
| Privacy layer stuck | Clear caches; check PrivacyLayer.js |

### Useful Queries

```sql
-- Check media records
SELECT uid, title, media_type, hidden FROM tx_mpcvidply_media;

-- Check MM relations
SELECT * FROM tx_mpcvidply_content_media_mm WHERE uid_local = [tt_content.uid];

-- Check file references
SELECT * FROM sys_file_reference WHERE tablenames = 'tx_mpcvidply_media';
```

### Debug Mode

Enable TYPO3 debug mode to see detailed errors:

```php
$GLOBALS['TYPO3_CONF_VARS']['BE']['debug'] = true;
$GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] = true;
```

---

## 📚 Documentation Index

| File | Content |
|------|---------|
| [AssetLoading.md](AssetLoading.md) | Conditional JS loading optimization |
| [Partials.md](Partials.md) | Template partial documentation |
| [PrivacyLayer.md](PrivacyLayer.md) | GDPR implementation details |
| [HLS-Implementation.md](HLS-Implementation.md) | HLS & DASH streaming technical details (integrated into video/audio types) |
| [SettingsArchitecture.md](SettingsArchitecture.md) | Configuration system |
| [Editors-Guide.md](Editors-Guide.md) | Guide for content editors |

---

## 🔗 External Resources

- [TYPO3 Documentation](https://docs.typo3.org/)
- [HLS.js](https://github.com/video-dev/hls.js/)
- [dash.js](https://github.com/Dash-Industry-Forum/dash.js/)
- [WebVTT Validator](https://quuz.org/webvtt/)
- [WCAG 2.2 Media Guidelines](https://www.w3.org/WAI/media/av/)

---

## Quick Reference

### Install

```bash
composer require mpc/mpc-vidply
# → Database update → Include Site Set → Clear caches
```

### Key Files

| What | Where |
|------|-------|
| Media TCA | `Configuration/TCA/tx_mpcvidply_media.php` |
| Content TCA | `Configuration/TCA/Overrides/tt_content.php` |
| DataProcessor | `Classes/DataProcessing/VidPlyProcessor.php` |
| Privacy Service | `Classes/Service/PrivacySettingsService.php` |
| Main Template | `Resources/Private/Templates/VidPly.html` |
| Privacy JS | `Resources/Public/JavaScript/PrivacyLayer.js` |
| Privacy CSS | `Resources/Public/Css/privacy-layer.css` |

### Database

```sql
-- Tables
tx_mpcvidply_media          -- Media library
tx_mpcvidply_content_media_mm -- Content-to-media relation
tx_mpcvidply_privacy_settings -- Privacy layer configuration
```

---

**Version:** 1.1.3 | **TYPO3:** 13.4+ / 14.x | **PHP:** ≥8.2

