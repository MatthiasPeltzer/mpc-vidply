# VidPly Settings Architecture

Where configuration lives: **extension-wide defaults**, **site-wide privacy**, **content-element player options**, and **per-media record** fields.

## Overview

| Tier | Where configured | Scope |
|------|------------------|-------|
| Extension Configuration | Admin → Settings → Extension Configuration → VidPly | Whole TYPO3 installation |
| Privacy Layer Settings | List module → Privacy Layer Settings | Site-wide external-service consent copy |
| Player (content element) | Page module → VidPly Player CE | One player instance on a page |
| Media record | List module → VidPly Media | Reusable item (any player/listview) |

See also [Integrations.md](Integrations.md) for mp-core site settings that affect JSON-LD output.

## Current Architecture

### Extension Configuration (global)

Configured in **Admin → Settings → Extension Configuration → mpc_vidply** (`ext_conf_template.txt`):

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `allowedVideoDomains` | string | *(empty)* | Allow-list for external video URLs (comma/newline; wildcards supported) |
| `allowedAudioDomains` | string | *(empty)* | Allow-list for external audio URLs |
| `playIcon` | string | *(empty)* | Custom play-button image: `EXT:…` path, site-relative path, or HTTPS URL |
| `allowedPlayIconDomains` | string | *(empty)* | Allow-list for external play-icon URLs (required when using HTTPS icons) |
| `playPosition` | select | `center` | Play overlay position: center, corners |
| `useCssIcons` | bool | `0` | Use CSS-based control-bar icons (enables theming via CSS variables) |
| `theme` | select | `dark` | Player chrome: `dark` or `light` |
| `themeSyncEnabled` | bool | `0` | Sync player theme with page light/dark mode (body class + custom events) |

These apply to every frontend player unless overridden in Fluid/TypoScript.

### Privacy Layer Settings (tx_mpcvidply_privacy_settings)
**Site-wide settings for external service privacy layers:**

| Setting | Type | Description |
|---------|------|-------------|
| Headline | String | Optional headline above privacy text |
| Intro Text | Text | Text before privacy policy link |
| Outro Text | Text | Text after privacy policy link |
| Policy Link | String | URL to privacy policy page |
| Link Text | String | Text for privacy policy link |
| Button Label | String | Accessible label for play button |

Available for YouTube, Vimeo, and SoundCloud. Supports multilingual content via `sys_language_uid`. Empty fields fall back to language file translations.

### Player Settings (tt_content)
**Global settings that apply to entire player instance:**

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Options | Bitmask | 328 | Player features (see below) |
| Volume | Float | 0.8 | Initial volume (0-1) |
| Playback Speed | Float | 1.0 | Initial speed (0.25-2x) |
| Language | String | Auto | UI language |

### Player Options (Bitmask)

| Bit | Value | Option | Default |
|-----|-------|--------|---------|
| 0 | 1 | Autoplay | Off |
| 1 | 2 | Loop | Off |
| 2 | 4 | Muted | Off |
| 3 | 8 | Controls | On |
| 4 | 16 | Captions Default | Off |
| 5 | 64 | Keyboard | On |
| 6 | 256 | Auto Advance | On |

**Default**: 8 + 64 + 256 = **328**

**Notes:**
- Responsive sizing is always enabled (no toggle).
- Transcript is controlled per media record (`tx_mpcvidply_media.enable_transcript`). The transcript UI is available if at least one selected item enables it.

### Media Item Settings (tx_mpcvidply_media)
**Per-item settings specific to each media record:**

| Setting | Description |
|---------|-------------|
| Media Type | video, audio, youtube, vimeo, soundcloud |
| Media File/URL | Source file or URL |
| Title | Track title |
| Slug | URL slug for listview detail routing (auto from title) |
| Artist | Creator/artist name |
| Description | Short text (list cards, player) |
| Long description | RTE copy for detail page |
| Duration | Length in seconds |
| Poster | Thumbnail image |
| Categories | TYPO3 categories (listview chips + category-based rows) |
| Captions | WebVTT / SRT caption files |
| Chapters | WebVTT / SRT chapter files |
| Enable Transcript | Per-track transcript flag |
| Audio Description | Described video (MP4/WebM swap) or VTT speech via `audio_description_mode` |
| Audio Description Mode | `auto` \| `swap` \| `vtt_speech` — delivery path for spoken AD |
| Sign Language | Sign language overlay video |
| Hide speed button | Hide playback-speed control for this item (per-track in playlists) |
| Hide keyboard shortcuts help | Hide help button for this item (per-track in playlists) |
| Allow download | Show download button (uses progressive source when available) |
| Enable floating player | Custom draggable PiP window (single-item players only) |

## Best Practices

### Extension Configuration (Installation-Wide)
Use for branding and security defaults that should not vary per page:
- Allowed external media domains
- Default dark/light theme and page theme sync
- Custom play icon for privacy overlay and player
- CSS icon mode for design-system integration

### Privacy Layer Settings (Site-Wide)
Use for settings that should be **consistent across all external services**:
- Privacy notice texts
- Policy links
- Button labels
- Multilingual translations

### Player-Level (Global)
Use for settings that should be **consistent across all tracks**:
- UI behavior (controls, keyboard)
- Initial state (volume, speed)
- Playlist behavior (auto-advance)

### Media-Level (Per-Item)
Use for settings that are **specific to content**:
- Source files/URLs and slug
- Metadata (title, artist, descriptions, categories)
- Accessibility features (captions, chapters, AD, sign language)
- Visual elements (poster)
- Per-item UI toggles (download, floating player, hide speed/help)

## Rationale

This separation provides:
- **Consistency** - Same privacy layer UX across all external services
- **Centralization** - Privacy texts managed in one place
- **Multilingual** - Privacy settings can be translated per language
- **Flexibility** - Per-item content customization
- **Maintainability** - Clear distinction between site-wide/global/local settings
- **Reusability** - Media items work in different contexts

## Future Considerations

Potential improvements:
- Per-item caption default (for multilingual content)
- Per-item loop/muted overrides (some playlist track behaviour already exists for speed/help visibility)
- Template overrides for custom use cases

Current architecture is solid and follows TYPO3 best practices.
