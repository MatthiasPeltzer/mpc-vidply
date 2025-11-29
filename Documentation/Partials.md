# VidPlay Template Partials

Modular template structure for maintainability and customization.

## Location

`Resources/Private/Partials/VidPly/`

## Available Partials

### 1. Assets.html

**Conditionally registers CSS and JavaScript assets**

```html
<f:render partial="VidPly/Assets" arguments="{
    needsPrivacyLayer: vidply.needsPrivacyLayer,
    needsVidPlay: vidply.needsVidPlay,
    needsPlaylist: vidply.needsPlaylist,
    needsHLS: vidply.needsHLS
}" />
```

**Arguments:**
- `needsPrivacyLayer` - Load PrivacyLayer.js (external services)
- `needsVidPlay` - Load VidPlay core (native player)
- `needsPlaylist` - Load PlaylistInit.js (playlists/player init)
- `needsHLS` - Load hls.js (HLS streaming)

**Always loads:**
- `vidply.min.css` - Player styling

**Conditionally loads based on media types:**
- `PrivacyLayer.js` - YouTube, Vimeo, SoundCloud
- `hls.js` (CDN) - HLS streams only
- `VidPlyWrapper.js` - Native player only
- `PlaylistInit.js` - Playlists or player init
- `vidply.esm.min.js` - Native player only

See `Documentation/AssetLoading.md` for optimization details.

---

### 2. VideoSources.html

**Renders `<source>` elements for video players**

```html
<f:render partial="VidPly/VideoSources" arguments="{
    videoUrl: vidply.videoUrl,
    playlistData: vidply.playlistData,
    sources: vidply.sources,
    mediaFiles: vidply.mediaFiles
}" />
```

Handles:
- External sources (YouTube, Vimeo, HLS)
- Multiple quality/format options
- Playlist mode
- Audio description sources

---

### 3. AudioSources.html

**Renders `<source>` elements for audio players**

```html
<f:render partial="VidPly/AudioSources" arguments="{
    playlistData: vidply.playlistData,
    tracks: vidply.tracks,
    sources: vidply.sources,
    mediaFiles: vidply.mediaFiles
}" />
```

Handles:
- Multiple formats (MP3, OGG)
- Playlist mode
- Format fallback

---

### 4. Tracks.html

**Renders caption and chapter `<track>` elements**

```html
<f:render partial="VidPly/Tracks" arguments="{
    captions: vidply.captions,
    chapters: vidply.chapters,
    languageSelection: vidply.languageSelection
}" />
```

Shared by video and audio players. Supports multiple languages and track kinds.

---

### 5. MetadataScripts.html

**JSON metadata for accessibility features**

```html
<f:render partial="VidPly/MetadataScripts" arguments="{
    uniqueId: vidply.uniqueId,
    audioDescriptionTracks: vidply.audioDescriptionTracks,
    audioDescription: vidply.audioDescription,
    signLanguage: vidply.signLanguage
}" />
```

Used by video players for audio description and sign language.

---

### 6. PrivacyLayer.html

**GDPR consent layer for external services**

```html
<f:render partial="VidPly/PrivacyLayer" arguments="{
    service: 'youtube',
    videoUrl: vidply.videoUrl,
    poster: vidply.poster,
    title: data.header,
    uniqueId: vidply.uniqueId
}" />
```

Displays play button with privacy notice for YouTube, Vimeo, and SoundCloud. Loads iframe on consent.

---

## Template Structure

```
VidPly.html (Main)
├── Header
├── Check if external service?
│   ├── YES → PrivacyLayer
│   └── NO → VideoPlayer or AudioPlayer
│       ├── VideoSources / AudioSources
│       ├── Tracks
│       └── MetadataScripts (video only)
└── Assets
```

## Customization

### Override in Sitepackage

**TypoScript:**
```typoscript
tt_content.mpc_vidply {
    partialRootPaths.100 = EXT:your_sitepackage/Resources/Private/Partials/
}
```

**Create custom partial:**
```
your_sitepackage/
└── Resources/Private/Partials/VidPly/
    └── VideoSources.html  ← Your customization
```

Other partials remain unchanged.

## Benefits

- **Single Responsibility** - Each partial has one clear purpose
- **Reusability** - Tracks partial shared by video/audio
- **Easy Override** - Customize only what you need
- **Maintainability** - Changes isolated to specific files
- **Readability** - Main template reduced from 250 to 130 lines  

## No Breaking Changes

Refactoring maintains identical HTML output and functionality. Existing configurations work without modification.
