# VidPly TYPO3 Extension

Universal, accessible video and audio player for **TYPO3 13/14** with YouTube, Vimeo, SoundCloud, and HLS streaming support.

> **Modern Extension** - Built for TYPO3 13.4+ and PHP 8.2+ with pure dependency injection and readonly properties.

## Key Features

- **Privacy-First External Services** - YouTube, Vimeo, SoundCloud with GDPR-compliant consent layer
- **Media Library** - Reusable media records across your site
- **Auto Playlists** - 2+ items automatically create playlists
- **Full Accessibility** - Captions, chapters, audio description, sign language, keyboard controls
- **HLS Streaming** - Adaptive bitrate streaming with hls.js
- **Modern Player** - Responsive, Picture-in-Picture, quality switching, playback speed

## Quick Start

### 1. Install

```bash
composer require mpc/mpc-vidply
```

Then:
1. **Database Update** → Maintenance → Analyze Database Structure
2. **Include Static Template** → Root page → "VidPly Player (mpc_vidply)"
3. **Clear Caches**

### 2. Create Media

**List Module → VidPly Media**

Choose media type:
- **HTML5 Video/Audio** - Upload MP4, WebM, MP3
- **YouTube** - Paste video URL
- **Vimeo** - Paste video URL  
- **SoundCloud** - Paste track/set URL
- **HLS** - Enter .m3u8 stream URL

Add:
- Title
- Poster image
- Captions (WebVTT)
- Chapters (optional)

### 3. Add to Page

**Page Module → Add Content → VidPly Player**

- Select media items (1 = single player, 2+ = playlist)
- Configure player options
- Save and view!

## Media Types

| Type | Source | Privacy Layer | Notes |
|------|--------|--------------|-------|
| HTML5 Video | Upload/URL | No | MP4, WebM, OGG |
| HTML5 Audio | Upload/URL | No | MP3, OGG, WAV |
| YouTube | Video URL | Yes | GDPR consent required |
| Vimeo | Video URL | Yes | GDPR consent required |
| SoundCloud | Track/Set URL | Yes | GDPR consent required |
| HLS | .m3u8 URL | No | Adaptive streaming |

### Privacy Layer

For YouTube, Vimeo, and SoundCloud:
- **No tracking** before user consent
- **Play button overlay** with privacy notice
- **One-click activation** - loads and plays immediately
- **Auto-translated** - German and English supported
- **No VidPlay** - Uses native service players

## Configuration

### Player Options

| Option | Default | Description |
|--------|---------|-------------|
| Controls | On | Show player controls |
| Keyboard | On | Enable shortcuts (Space, M, F, etc.) |
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

### Captions/Subtitles
Upload WebVTT (.vtt) files with:
- Language code (en, de, fr, etc.)
- Track kind (captions, subtitles, descriptions)
- Multiple languages supported

### Chapters
WebVTT file with timestamps for section navigation

### Audio Description
Alternative audio track for visually impaired users

### Sign Language
Overlay video with sign language interpretation

### Transcripts
Auto-generated searchable transcripts from captions

## Playlists

**Single Item** → Single player
**2+ Items** → Automatic playlist with:
- Visual panel with thumbnails
- Track navigation
- Auto-advance option
- Loop through all tracks

## Advanced

### Conditional Asset Loading

VidPlay intelligently loads only the JavaScript needed for your specific media types:
- **External services** (YouTube, Vimeo, SoundCloud) - Only PrivacyLayer.js (~5KB)
- **Local video/audio** - VidPlay core + PlaylistInit (~180KB)
- **HLS streaming** - Adds hls.js only when .m3u8 streams detected
- **Playlists** - PlaylistInit.js loads for 2+ items

Performance improvement: Up to 97% reduction for external services.

See `Documentation/AssetLoading.md` for details.

### Template Partials

Modular template structure:
- `VidPly/Assets.html` - Asset registration
- `VidPly/VideoSources.html` - Video source rendering
- `VidPly/AudioSources.html` - Audio source rendering
- `VidPly/Tracks.html` - Caption/chapter tracks
- `VidPly/MetadataScripts.html` - Accessibility metadata
- `VidPly/PrivacyLayer.html` - External service consent

See `Documentation/Partials.md` for customization.

### HLS Streaming

Full HLS support with hls.js:
- Works in all modern browsers
- Adaptive bitrate streaming
- CSP compatible
- See `Documentation/HLS-Implementation.md`

### Privacy Layer

GDPR-compliant external service loading:
- Lazy iframe creation
- No tracking before consent
- Translated privacy notices
- See `Documentation/PrivacyLayer.md`

## Documentation

- `AssetLoading.md` - Conditional asset loading optimization
- `Partials.md` - Template structure and customization
- `PrivacyLayer.md` - External service privacy implementation
- `HLS-Implementation.md` - HLS streaming technical details
- `SettingsArchitecture.md` - Configuration system

## Troubleshooting

**Media not showing?**
- Check media record is not hidden
- Verify file/URL is accessible
- Check database MM relation

**Playlist not working?**
- Need 2+ items for playlist
- Check JavaScript console for errors
- Verify PlaylistInit.js loads

**Captions not loading?**
- Validate VTT file syntax
- Check CORS headers
- Ensure language code is set

**Privacy layer not working?**
- Clear TYPO3 caches
- Check PrivacyLayer.js loads
- Verify service URL is correct

## Requirements

- **TYPO3**: 13.4+ or 14.x
- **PHP**: 8.2+ (8.3 recommended)
- **Composer**: Required
- **Browsers**: Chrome 90+, Firefox 88+, Safari 14+

## License

GNU General Public License v2.0 or later

## Author

Matthias Peltzer

---

**Made for accessible media in TYPO3**
