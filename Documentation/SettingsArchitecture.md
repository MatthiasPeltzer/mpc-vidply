# VidPlay Settings Architecture

Analysis of settings distribution between Player (content element) and Media Items (media records).

## Current Architecture

### Player Settings (tt_content)
**Global settings that apply to entire player instance:**

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Width | Number | 800 | Player width (px) |
| Height | Number | 450 | Player height (px) |
| Options | Bitmask | 456 | Player features (see below) |
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
| 5 | 32 | Transcript | Off |
| 6 | 64 | Keyboard | On |
| 7 | 128 | Responsive | On |
| 8 | 256 | Auto Advance | On |

**Default**: 8 + 64 + 128 + 256 = **456**

### Media Item Settings (tx_mpcvidply_media)
**Per-item settings specific to each media record:**

| Setting | Description |
|---------|-------------|
| Media Type | video, audio, youtube, vimeo, soundcloud, hls, m3u |
| Media File/URL | Source file or URL |
| Title | Track title |
| Artist | Creator/artist name |
| Description | Track description |
| Duration | Length in seconds |
| Poster | Thumbnail image |
| Captions | WebVTT caption files |
| Chapters | WebVTT chapter markers |
| Enable Transcript | Per-track transcript flag |
| Audio Description | Alternative audio track |
| Sign Language | Sign language overlay video |

## Best Practices

### Player-Level (Global)
Use for settings that should be **consistent across all tracks**:
- UI behavior (controls, keyboard)
- Display (width, height, responsive)
- Initial state (volume, speed)
- Playlist behavior (auto-advance)

### Media-Level (Per-Item)
Use for settings that are **specific to content**:
- Source files/URLs
- Metadata (title, artist, description)
- Accessibility features (captions, chapters)
- Visual elements (poster)

## Rationale

This separation provides:
- **Consistency** - Same player UX for all tracks
- **Flexibility** - Per-item content customization
- **Maintainability** - Clear distinction between global/local settings
- **Reusability** - Media items work in different contexts

## Future Considerations

Potential improvements:
- Per-item loop/muted settings (for background videos)
- Per-item caption default (for multilingual content)
- Template overrides for custom use cases

Current architecture is solid and follows TYPO3 best practices.
