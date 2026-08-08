# VidPly Template Partials

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
    needsHLS: vidply.needsHLS,
    needsDASH: vidply.needsDASH
}" />
```

**Arguments:**
- `needsPrivacyLayer` - Load PrivacyLayer.js (external services)
- `needsVidPlay` - Load VidPly core (native player)
- `needsPlaylist` - Load PlaylistInit.js (playlists/player init)
- `needsHLS` - Load hls.js (HLS streaming)
- `needsDASH` - Load dash.js (DASH streaming)

**Always loads:**
- `vidply.min.css` - Player styling

**Conditionally loads based on media types:**
- `privacy-layer.css` - Privacy layer styles (external services only)
- `PrivacyLayer.js` - YouTube, Vimeo, SoundCloud
- `hls.min.js` — vendored **hls.js 1.6.16** (HLS streams only)
- `dash.all.min.js` — vendored **dash.js 5.2.0** (modern UMD; DASH streams only)
- `PlaylistInit.js` - Playlists or player init
- `vidply/vidply.esm.min.js` - Native player only (plus code-split chunks)

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
- Multiple source formats with fallback order (DASH → HLS → MP4/WebM)
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
- Multiple source formats with fallback order (DASH → HLS → MP3/OGG)
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

**JSON payloads for audio description and sign language** (read by the player at init — not JSON-LD)

```html
<f:render partial="VidPly/MetadataScripts" arguments="{
    uniqueId: vidply.uniqueId,
    audioDescriptionTracks: vidply.audioDescriptionTracks,
    audioDescription: vidply.audioDescription,
    signLanguage: vidply.signLanguage
}" />
```

Used by video players for audio description and sign language track metadata.

> **SEO JSON-LD** (`VideoObject` / `AudioObject`) is emitted separately in the page `<head>` via `Partials/Page/StructuredDataVideo.html` — see [Developers Quickstart → Structured data](Developers-Quickstart.md#structured-data-json-ld).

---

### 6. PrivacyLayer.html

**GDPR consent layer for external services**

```html
<f:render partial="VidPly/PrivacyLayer" arguments="{
    service: 'youtube',
    videoUrl: vidply.videoUrl,
    poster: vidply.poster,
    title: data.header,
    uniqueId: vidply.uniqueId,
    privacySettings: vidply.privacySettings.youtube
}" />
```

Displays play button with privacy notice for YouTube, Vimeo, and SoundCloud. Uses database settings from `tx_mpcvidply_privacy_settings` table with fallback to language file translations. Loads iframe on consent.

**Privacy settings structure:**
```php
[
    'headline' => 'Optional headline',
    'intro_text' => 'Text before link',
    'outro_text' => 'Text after link',
    'policy_link' => 'https://...',
    'link_text' => 'Link text',
    'button_label' => 'Button aria-label'
]
```

---

## Template Structure

```
VidPly.html (Main)
├── layout = card|episodes → VidPly/EpisodeLayout.html
│   ├── VidPly/EpisodeCard.html (cover, episode number, title, date, duration, description)
│   └── VidPly/Player.html
└── VidPly/Player.html (wrapper + renderMode switch)
    ├── privacy → PrivacyLayer.html
    ├── mixedPlaylist → MixedPlaylistPlayer.html
    ├── audio → AudioPlayer.html
    └── video → VideoPlayer.html
        ├── VideoSources.html / AudioSources.html
        ├── Tracks.html
        └── MetadataScripts.html (video only)
    └── Assets.html (conditional JS/CSS)
```

Page-level: `Partials/Page/StructuredDataVideo.html` (JSON-LD in `<head>`).

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

---

## Listview & detail templates

Location: `Resources/Private/Partials/Listview/` and
`Resources/Private/Templates/Listview.html` / `Detail.html`

| Partial / template | Role |
|--------------------|------|
| `Listview/Row` | One shelf or grid row: headline, sort `<select>`, optional paginate wrapper |
| `Listview/RowListBody` | Shared `<ul>` for grid or shelf (used with or without pagination) |
| `Listview/Card` | Single media card (poster, title, artist, category chips, link) |
| `Listview/ShelfArrows` | Previous/next controls for the horizontal shelf |

Override the same way with `partialRootPaths` for `mpc_vidply` listview
TypoScript if your sitepackage needs different markup; keep `data-*`
attributes if you rely on `Listview.js` (shelf, sort, pagination).

## Episode card partials

Used when the content element's **Layout** field (`tx_mpcvidply_layout`) is set
to *Episode card* or *Episode card with episode list*; `default` renders the
plain player exactly as before.

| Partial | Role |
|---------|------|
| `VidPly/EpisodeLayout` | Layout wrapper, loads `episode.min.css` + `EpisodeInit.min.js`, renders the card or the episode list plus the player |
| `VidPly/EpisodeCover` | Square artwork of one episode |
| `VidPly/EpisodeCard` | Round play button next to episode number, title, publish date, duration, description |

`EpisodeCard` takes a `titleLevel` argument (3 for the single card, 4 for list
rows) so the heading order stays gapless below the content element header.

### The two layouts

Both open with the same card: `mpc-episode-cover` next to `mpc-episode-main`,
which stacks the card body on the player. *Episode card* stops there and shows
`vidply.episode`, the first record.

*Episode card with episode list* adds a `mpc-episode-list-section` that breaks to
a full-width row below it, listing every record in `vidply.episodes` as an `<ol>`
of `mpc-episode-item` rows. The card then acts as the now-playing header:
`EpisodeInit.js` clones the selected row into it on every track change, so the
cover, title, date, duration, categories and description always belong to the
track in the player. Cloning keeps the server-rendered markup as the single
source of episode data — nothing is re-formatted in JavaScript.

Because the list **is** the track list, the processor turns the player's own
playlist panel off (`showPanel: false`) and hides its toggle button
(`playlistToggleButton: false`) — otherwise the same records would be rendered
twice, by two different formatters.

Episode data comes from `VidPlyProcessor::buildEpisodeData()`, scoped per layout
by `resolveEpisodesForLayout()` so *Episode card* does not pay for poster and
category lookups of records it never prints. Dates, durations and download sizes
are pre-formatted in PHP for the site language, so templates print ready-made
strings.

Only records that produced a playable track make it into `vidply.episodes`, which
is what keeps `episode.index` usable as the playlist track index: a record whose
source cannot be resolved is dropped from both lists at once.

### Downloads in the episode list

`episode.downloadUrl` and `episode.downloadInfo` ("MP3, 7.4 MB") are filled for
records with **Allow download** — but only in the *Episode list* layout of a
playlist. Every episode is on the page there, so a link per row lets visitors
save any of them without selecting it first.

Every other combination leaves the download to the player's own control-bar
button, which resolves its file from the selected playlist track
(`track.downloadUrl` / `downloadFormat` / `downloadFileSize`, see
`VidPlyProcessor::enrichTrackWithDownloadData()`). That is also why *Episode
card* prints no link: it shows one episode while the player may already be on
another, so a server-rendered link there would age.

Exactly one affordance is offered in every case: `buildPlaylistData()` only sets
`downloadButton: true` outside the *Episode list* layout.

The size is measured on the storage (`ResourceStorage::getFileInfo()`), not taken
from `sys_file.size`: a file replaced without re-indexing would otherwise be
announced with its predecessor's size. Passing the same byte count to the player
keeps both labels in agreement and saves the `HEAD` request the button would
otherwise send. If the storage cannot answer, the row label falls back to the
format alone and the player measures the file itself.

Inside a row the link needs `position: relative` and a `z-index` above the play
button's stretched overlay, or the row-wide play target swallows its clicks.

If you override the card, keep these hooks — `EpisodeInit.js` relies on them:

| Attribute | Purpose |
|-----------|---------|
| `data-mpc-episode-play` | Marks the play button. Clicks are delegated, so a cloned button works without re-initialisation. Inside a row its `::after` stretches over the row, the same trick as Bootstrap's `.stretched-link` in the teasers, which is why the row needs `position: relative` |
| `data-mpc-episode-index` | Playlist track the button selects (`playlistManager.play(index)`) |
| `data-mpc-episode-item` | Row wrapper. Receives `aria-current="true"` while its track is selected, and is the clone source for the card |
| `data-mpc-episode-current` | On the root: index the card currently shows. Rendered as `0` so the first paint has nothing to swap |

Class names follow the Bootstrap pattern (`mpc-episode`, `mpc-episode-cover`,
`mpc-episode-body`, `mpc-episode-play`, …) with the layout as a modifier on the
root element (`mpc-episode-layout-card`, `mpc-episode-layout-episodes`). The
artwork sits in `mpc-episode-cover` and everything else in `mpc-episode-main`, so
the player lines up with the play button rather than the image. List rows shrink
the cover and play bubble by overriding `--mpc-vidply-episode-cover` and
`--mpc-vidply-episode-play-size`, which is why a row cloned into the card comes
out at full size.

Heading levels differ between the two positions — `titleLevel` 3 for the card, 4
for rows — so the clone takes over the tag of the title it replaces instead of
carrying the row's `<h4>` up to the top of the element.

Two things to know if you restyle a row: the play button must not be given a
`transform`, because a transformed element becomes the containing block of its
own overlay and the click area would snap back to the bubble on hover (the row's
hover and `:focus-within` states carry the affordance instead). And the overlay
covers the row's text, so selecting a row's description with the mouse is not
possible — the same trade-off the teasers make.

## Benefits

- **Single Responsibility** - Each partial has one clear purpose
- **Reusability** - Tracks partial shared by video/audio
- **Easy Override** - Customize only what you need
- **Maintainability** - Changes isolated to specific files
- **Readability** - Main template reduced from 250 to 130 lines  

## No Breaking Changes

Refactoring maintains identical HTML output and functionality. Existing configurations work without modification.
