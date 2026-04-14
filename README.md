# VidPly TYPO3 Extension

Universal, Accessible Video & Audio Player for TYPO3. Includes support for HTML5 video/audio, YouTube, Vimeo, SoundCloud, HLS and DASH streaming, playlists, captions, transcripts, sign language, and WCAG 2.2 AA accessibility compliance.

## Features

- **Privacy-First External Services** - YouTube, Vimeo, SoundCloud with GDPR-compliant consent layer
- **Site-Wide Privacy Settings** - Centralized backend configuration for privacy layer texts, links, and headlines
- **Media Library** - Reusable media records across your site
- **Auto Playlists** - 2+ items automatically create playlists
- **Full Accessibility** - Captions, chapters, audio description, sign language, keyboard controls
- **HLS Streaming** - Adaptive bitrate streaming with hls.js (integrated into video/audio types)
- **DASH Streaming** - MPEG-DASH via dash.js with adaptive quality and subtitles (integrated into video/audio types)
- **Modern Player** - Responsive, Picture-in-Picture, quality switching, playback speed
- **Conditional Asset Loading** - Only loads JavaScript needed for your media types

## Quick Start

### Install

```bash
composer require mpc/mpc-vidply
```

1. **Database Update** → Maintenance → Analyze Database Structure
2. **Include Static Template** → Root page → "VidPly Player (mpc_vidply)"
3. **Clear Caches**

### Create Media

**List Module → VidPly Media**

Choose media type:
- **Video** - Upload MP4, WebM or add HLS/DASH streaming URLs (with progressive fallbacks)
- **Audio** - Upload MP3, OGG or add HLS/DASH streaming URLs (with progressive fallbacks)
- **YouTube** - Paste video URL
- **Vimeo** - Paste video URL
- **SoundCloud** - Paste track/set URL

### Configure Privacy Layer Settings

**List Module → Privacy Layer Settings**

Manage privacy layer content for YouTube, Vimeo, and SoundCloud:
- **Headline** (optional) - Display above privacy text
- **Intro Text** - Text before privacy policy link
- **Outro Text** - Text after privacy policy link
- **Policy Link** - URL to privacy policy page
- **Link Text** - Text for the privacy policy link
- **Button Label** (optional) - Accessible label for play button

Settings support multilingual content and apply to both single items and playlists. Empty fields fall back to default translations.

### Add to Page

**Page Module → Add Content → VidPly Player**

- Select media items (1 = single player, 2+ = playlist)
- Configure player options
- Save and view

## Media Types

| Type | Source | Privacy Layer | Notes |
|------|--------|--------------|-------|
| Video | Upload/URL | No | MP4, WebM, HLS (.m3u8), DASH (.mpd) with fallbacks |
| Audio | Upload/URL | No | MP3, OGG, HLS (.m3u8), DASH (.mpd) with fallbacks |
| YouTube | Video URL | Yes | GDPR consent required |
| Vimeo | Video URL | Yes | GDPR consent required |
| SoundCloud | Track/Set URL | Yes | GDPR consent required |

## Privacy Layer

For YouTube, Vimeo, and SoundCloud:
- No tracking before user consent
- Play button overlay with privacy notice
- One-click activation - loads and plays immediately
- Centralized backend configuration for all privacy texts
- Multilingual support for privacy settings
- Auto-translated fallbacks (German and English)

## Configuration

### Player Options

| Option | Default | Description |
|--------|---------|-------------|
| Controls | On | Show player controls |
| Keyboard | On | Enable shortcuts |
| Responsive | On | Responsive sizing |
| Auto Advance | On | Auto-play next in playlist |
| Autoplay | Off | Start automatically |
| Loop | Off | Loop content |
| Transcript | Off | Show transcript panel |

### Keyboard Shortcuts

- **Space/K** - Play/Pause
- **M** - Mute
- **F** - Fullscreen
- **C** - Captions
- **←/→** - Seek ±10s
- **↑/↓** - Volume ±10%

## Accessibility

- **Captions/Subtitles** - WebVTT files with language codes
- **Chapters** - WebVTT file with timestamps for navigation
- **Audio Description** - Alternative audio tracks for visually impaired users
- **Sign Language** - Overlay videos with sign language interpretation
- **Transcripts** - Auto-generated searchable transcripts from captions

## Playlists

**Single Item** → Single player  
**2+ Items** → Automatic playlist with visual panel, track navigation, auto-advance, and loop options

**Lazy loading behavior (video/audio including HLS/DASH sources):**
- If **Autoplay is off**, the extension configures VidPly to **defer network loading** until the user starts playback (reduced initial bandwidth for pages with many players).
- In **playlists**, selecting a track initializes the UI/metadata (poster, duration, captions/chapters menus) and a click on a playlist item **loads and plays** that track.

## Advanced

### Conditional Asset Loading

Only loads JavaScript needed for your media types:
- **External services** - PrivacyLayer.js (~5KB)
- **Video/Audio** - VidPly core + PlaylistInit (~180KB)
- **HLS sources** - Adds hls.js when .m3u8 streams detected
- **DASH sources** - Adds dash.js when .mpd streams detected
- **Playlists** - PlaylistInit.js loads for 2+ items

Performance improvement: Up to 97% reduction for external services.

### Template Structure

Modular template partials:
- `VidPly/Assets.html` - Asset registration
- `VidPly/VideoSources.html` - Video source rendering
- `VidPly/AudioSources.html` - Audio source rendering
- `VidPly/Tracks.html` - Caption/chapter tracks
- `VidPly/MetadataScripts.html` - Accessibility metadata
- `VidPly/PrivacyLayer.html` - External service consent

## Documentation

- [Editors Guide](Documentation/Editors-Guide.md) - How to use VidPly for content editors
- [Developers Quickstart](Documentation/Developers-Quickstart.md) - Quick reference for developers
- [AssetLoading.md](Documentation/AssetLoading.md) - Conditional asset loading optimization
- [Partials.md](Documentation/Partials.md) - Template structure and customization
- [PrivacyLayer.md](Documentation/PrivacyLayer.md) - External service privacy implementation
- [HLS-Implementation.md](Documentation/HLS-Implementation.md) - HLS & DASH streaming technical details
- [SettingsArchitecture.md](Documentation/SettingsArchitecture.md) - Configuration system architecture

## Troubleshooting

**Media not showing?**
- Check media record is not hidden
- Verify file/URL is accessible
- Check database MM relation

**Playlist not working?**
- Need 2+ items for playlist
- Check JavaScript console for errors
- Verify PlaylistInit.js loads

**Privacy layer not working?**
- Clear TYPO3 caches
- Check PrivacyLayer.js loads
- Verify Privacy Layer Settings are configured

## Requirements

- **TYPO3**: 13.4+ or 14.x
- **PHP**: 8.2+ (8.3 recommended)
- **Composer**: Required
- **Browsers**: Chrome 90+, Firefox 88+, Safari 14+

## License

GNU General Public License v2.0 or later

## Author

Matthias Peltzer
