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

---

## 📁 Directory Structure

```
mpc_vidply/
├── Classes/
│   ├── Backend/
│   │   ├── Form/FieldWizard/     # MediaTypeFilterWizard
│   │   └── Preview/              # VidPlyPreviewRenderer
│   └── DataProcessing/           # VidPlyProcessor
├── Configuration/
│   ├── Sets/mpc-vidply/          # Site Set (TYPO3 13.4+)
│   ├── TCA/
│   │   ├── tx_mpcvidply_media.php # Media record TCA
│   │   └── Overrides/
│   │       └── tt_content.php    # Content element TCA
│   ├── TypoScript/
│   ├── ContentSecurityPolicies.php
│   ├── Icons.php
│   ├── JavaScriptModules.php
│   └── Services.yaml
├── Resources/
│   ├── Private/
│   │   ├── Language/             # XLF translations (en, de)
│   │   ├── Partials/VidPly/      # Modular template partials
│   │   └── Templates/            # Main VidPly.html template
│   └── Public/
│       ├── Css/vidply.min.css
│       ├── Icons/
│       └── JavaScript/
│           ├── hls.min.js        # HLS streaming
│           ├── PlaylistInit.js   # Playlist logic
│           ├── PrivacyLayer.js   # GDPR consent
│           └── vidply/           # Core player (ESM modules)
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

Media library records with types: `video`, `audio`, `youtube`, `vimeo`, `soundcloud`, `hls`, `m3u`

**Key columns:**

| Column | Type | Description |
|--------|------|-------------|
| `media_type` | string | Type discriminator |
| `media_file` | file | File references (video/audio) |
| `media_url` | string | External URL (SoundCloud, HLS) |
| `title` | string | Display title |
| `artist` | string | Creator name |
| `poster` | file | Thumbnail image |
| `captions` | file | VTT caption files |
| `chapters` | file | VTT chapter files |
| `audio_description` | file | AD video track |
| `sign_language` | file | Sign language overlay |
| `enable_transcript` | bool | Show transcript panel |

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
| `tx_mpcvidply_width` | number | Player width (px) |
| `tx_mpcvidply_height` | number | Player height (px) |
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
const TRANSCRIPT      = 32;   // Show transcript panel
const KEYBOARD        = 64;   // Enable keyboard shortcuts
const RESPONSIVE      = 128;  // Responsive sizing
const AUTO_ADVANCE    = 256;  // Auto-play next in playlist

// Default: 456 = CONTROLS + KEYBOARD + RESPONSIVE + AUTO_ADVANCE
```

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
tt_content.mpc_vidply =< lib.contentElement
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
{hasLocalMedia}        <!-- Has video/audio/hls -->
{hasExternalService}   <!-- Has YouTube/Vimeo/SoundCloud -->
{playerOptions}        <!-- Decoded options array -->
{privacySettings}      <!-- Privacy layer settings per service -->
```

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
| Local video/audio | vidply.esm.min.js + PlaylistInit.js | ~180KB |
| HLS streaming | + hls.min.js | +60KB |
| Playlist (2+ items) | + PlaylistInit.js | +5KB |

**Up to 97% reduction** for external-only content.

### Files

| File | Purpose |
|------|---------|
| `PrivacyLayer.js` | GDPR consent for external services |
| `PlaylistInit.js` | Playlist UI and navigation |
| `hls.min.js` | HLS.js for adaptive streaming |
| `vidply/*.js` | Core player (ESM, code-split) |

### Player Initialization

```javascript
// VidPly uses ESM modules with dynamic imports
import('vidply/vidply.esm.min.js').then(({ default: VidPly }) => {
    const player = new VidPly('#my-player', {
        controls: true,
        keyboard: true,
        responsive: true,
    });
});
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

## 🌐 HLS Streaming

### How It Works

1. Detects `.m3u8` URL in media record
2. Includes `hls.min.js` (only when needed)
3. Initializes HLS.js on video element
4. Provides quality switching UI

### CSP Configuration

`Configuration/ContentSecurityPolicies.php` whitelists HLS domains.

Add custom domains:

```php
return [
    'default-src' => ["'self'"],
    'media-src' => ["'self'", 'blob:', 'https://your-cdn.com'],
];
```

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
| HLS not working | Check CORS headers on .m3u8 |
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
| [HLS-Implementation.md](HLS-Implementation.md) | HLS streaming technical details |
| [SettingsArchitecture.md](SettingsArchitecture.md) | Configuration system |
| [Editors-Guide.md](Editors-Guide.md) | Guide for content editors |

---

## 🔗 External Resources

- [TYPO3 Documentation](https://docs.typo3.org/)
- [HLS.js](https://github.com/video-dev/hls.js/)
- [WebVTT Validator](https://quuz.org/webvtt/)
- [WCAG 2.1 Media Guidelines](https://www.w3.org/WAI/media/av/)

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

**Version:** 1.0.18 | **TYPO3:** 13.4+ / 14.x | **PHP:** ≥8.2

