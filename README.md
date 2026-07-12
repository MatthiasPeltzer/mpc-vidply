# VidPly TYPO3 Extension

Universal, Accessible Video & Audio Player for TYPO3. Includes support for HTML5 video/audio, YouTube, Vimeo, SoundCloud, HLS and DASH streaming, playlists, captions, transcripts, sign language, and WCAG 2.2 AA accessibility compliance.

## Features

- **Privacy-First External Services** - YouTube, Vimeo, SoundCloud with GDPR-compliant consent layer
- **Site-Wide Privacy Settings** - Centralized backend configuration for privacy layer texts, links, and headlines
- **Media Library** - Reusable media records across your site
- **Auto Playlists** - 2+ items automatically create playlists
- **Full Accessibility** - Captions, chapters, audio description, sign language, keyboard controls
- **HLS Streaming** - Adaptive bitrate streaming with **hls.js 1.6.16** (Chrome / Firefox / Edge / desktop Safari) and native HLS on iOS / iPadOS — both paths integrate with VidPly's captions, transcript and quality menus
- **DASH Streaming** - MPEG-DASH via **dash.js 5.2.0** (modern UMD) with adaptive quality and subtitles (integrated into video/audio types)
- **Import from URL** - Paste a media URL in the backend to auto-detect type, attach online media, and pre-fill title, artist, and poster
- **SoundCloud Support** - GDPR consent layer, then SoundCloud Widget playback (optional developer opt-in for unified VidPly controls — see [PrivacyLayer.md](Documentation/PrivacyLayer.md))
- **Buffering Spinner** - Centered loading spinner shown automatically while the media is buffering
- **Optional Download Button** - Per-media toggle; progressive sources (MP4/WebM/MP3) are used automatically; for HLS/DASH-only items add a progressive fallback file
- **Floating Player** - Optional custom Picture-in-Picture: draggable, resizable in-page window (per media record)
- **Modern Player** - Responsive, browser PiP, quality switching, playback speed, keyboard-shortcuts help overlay
- **Structured Data (JSON-LD)** - `VideoObject` / `AudioObject` (and `ItemList` on gallery pages) for SEO rich results — see [Developers Quickstart](Documentation/Developers-Quickstart.md#structured-data-json-ld)
- **TypeScript Codebase** - The bundled VidPly player is now authored in strict TypeScript with shipped `.d.ts` declarations
- **Conditional Asset Loading** - Only loads JavaScript needed for your media types
- **Listview & Detail Page** — Add a **VidPly Listview** content element for one or more browsable rows (horizontal shelf or responsive grid), optional per-row **browser pagination** and **sort** control, category chips on cards, and links to a **VidPly Detail** page with short + **long (RTE) description**, categories, and slug or `?media=` URLs; connected translations follow the default-language row configuration (see [`Documentation/Listview.md`](Documentation/Listview.md))

## Quick Start

### Install

```bash
composer require mpc/mpc-vidply
```

1. **Database Update** → Admin Tools → Maintenance → Analyze Database Structure
2. **Include Site Set** → Site Management → Sites → Your Site → Sets → Add `mpc/mpc-vidply`  
   (or add `mpc/mpc-vidply` to `dependencies` in `config/sites/<site>/config.yaml`)
3. **Clear Caches**

### Create Media

**List Module → VidPly Media**

**Fastest way:** use **Import from URL** at the top of the form — paste a YouTube, Vimeo, SoundCloud, streaming (`.m3u8` / `.mpd`), or allowlisted external MP4/MP3 link. VidPly detects the type and pre-fills metadata. See the [Editors Guide](Documentation/Editors-Guide.md).

Or choose media type manually:
- **Video** - Upload MP4, WebM or add HLS/DASH streaming URLs (with progressive fallbacks)
- **Audio** - Upload MP3, OGG or add HLS/DASH streaming URLs (with progressive fallbacks)
- **YouTube** - Paste video URL
- **Vimeo** - Paste video URL
- **SoundCloud** - Paste track/set URL

### Add Player to Page

**Page Module → Add Content → VidPly Player** — select one or more media records (2+ creates a playlist). See the [Editors Guide](Documentation/Editors-Guide.md) for player options, captions, playlists, and privacy behaviour.

### Build a Mediathek (Listview)

Add a **VidPly Listview** content element for browsable overview rows and a **VidPly Detail** page for single-media views (slug URLs, categories, long descriptions). Step-by-step: [Listview & Detail](Documentation/Listview.md).

## Documentation

Start at the **[Documentation index](Documentation/README.md)** for audience-based navigation (editors, administrators, developers).

| Guide | For |
|-------|-----|
| [Editors Guide](Documentation/Editors-Guide.md) | Creating media, players, captions, privacy layer |
| [Listview & Detail](Documentation/Listview.md) | Mediathek overview, detail pages, routing, i18n |
| [Developers Quickstart](Documentation/Developers-Quickstart.md) | Installation, processors, TCA, tests, JSON-LD |
| [Integrations](Documentation/Integrations.md) | Vue/Swiper, dynamic content, CSP, mp-core |
| [Settings Architecture](Documentation/SettingsArchitecture.md) | Extension, privacy, player, and media settings |
| [PrivacyLayer.md](Documentation/PrivacyLayer.md) | GDPR consent for YouTube, Vimeo, SoundCloud |
| [HLS-Implementation.md](Documentation/HLS-Implementation.md) | HLS & DASH streaming and CSP |
| [AssetLoading.md](Documentation/AssetLoading.md) | Conditional JavaScript loading |
| [Partials.md](Documentation/Partials.md) | Fluid template overrides |

## Troubleshooting

| Problem | See |
|---------|-----|
| Media or playlist issues | [Editors Guide → Troubleshooting](Documentation/Editors-Guide.md#troubleshooting) |
| Privacy layer / YouTube / Vimeo / SoundCloud | [PrivacyLayer.md](Documentation/PrivacyLayer.md) |
| HLS/DASH or CSP errors | [HLS-Implementation.md](Documentation/HLS-Implementation.md) |
| Player missing in Vue/Swiper slider | [Integrations.md](Documentation/Integrations.md) |
| Listview routing or slugs | [Listview.md](Documentation/Listview.md) |

## Requirements

- **TYPO3**: 13.4+ or 14.x (Core, Fluid, Extbase)
- **PHP**: 8.2+ (8.3 recommended)
- **Composer**: Required
- **Browsers**: Chrome 90+, Firefox 88+, Safari 14+

`fluid_styled_content` is optional. VidPly registers its own frontend content-rendering TypoScript (`lib.mpcVidplyContentElement`) and ships a compatible Default layout for `tt_content` headers and frame classes.

## License

GNU General Public License v2.0 or later

## Author

Matthias Peltzer
