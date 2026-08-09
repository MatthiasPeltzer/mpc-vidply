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

### 7. MediaPlayer.html

**The `<video>` / `<audio>` element itself**

`Player.html` sets `mediaKind` to `audio` or `video` and renders this one
partial for both. `mediaKind` is the element name as well, so the tag, the
default `aria-label`, the `poster` attribute (video only), the sources partial
and `MetadataScripts.html` (video only) all follow from it.

```html
<f:variable name="mediaKind" value="video" />
<f:render partial="VidPly/MediaPlayer" arguments="{_all}" />
```

It replaces the former `VideoPlayer.html` and `AudioPlayer.html`, which were
identical apart from those five points. Sitepackages that override either of
them must override `MediaPlayer.html` instead.

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
    └── audio / video → MediaPlayer.html (mediaKind = audio|video)
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
| `VidPly/EpisodeList` | The `<ol>` of episode rows, rendered bare or inside the pager container |
| `VidPly/EpisodeCover` | Square artwork of one episode |
| `VidPly/EpisodeCard` | Round play button next to episode number, title, publish date, duration, description, download link and long-description disclosure |

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

Episode data comes from `EpisodeListBuilder::build()`, scoped per layout so
*Episode card* does not pay for poster and category lookups of records it never
prints. Dates, durations and download sizes
are pre-formatted in PHP for the site language, so templates print ready-made
strings.

Only records that produced a playable track make it into `vidply.episodes`, which
is what keeps `episode.index` usable as the playlist track index: a record whose
source cannot be resolved is dropped from both lists at once.

### Sorting and pagination of the episode list

The list carries its own sort dropdown and, above a configurable page size, a
pager — modelled on the listview's (`Listview.js`), but self-contained in
`EpisodeInit.js`.

Both only touch the list. `episode.index` stays the playlist track index no
matter how the rows are ordered or which of them are hidden, so the player keeps
the editor's order for next/previous and auto-advance. That also means the
now-playing card is resolved as the episode with index `0`
(`EpisodeListBuilder::resolveLeadEpisode()`), not as the first array entry.

| Field (`tt_content`) | Effect |
|----------------------|--------|
| `tx_mpcvidply_episode_sort` | Preselected order: `sorting` (media item order), `date_desc`, `date_asc`, `title_asc`. Applied server-side and mirrored into the `<select>` |
| `tx_mpcvidply_episode_pagination` | Turns paging on; it only becomes visible once there are more episodes than fit on a page |
| `tx_mpcvidply_episode_per_page` | Episodes per page, clamped to 1–200 (default 10) |

Episodes without a publish date sort last in both date modes, on the server and
in the browser, so an incomplete record never leads a "newest first" list. Ties
fall back to the track index.

Hidden rows carry the `hidden` attribute, which takes them out of the tab order
and the accessibility tree; the heading keeps the full episode count while the
pager states the current page. Paging moves focus to the first row of the new
page, and a track change reveals the page holding the active row — so
auto-advancing past the end of a page brings the list along.

| Attribute | Purpose |
|-----------|---------|
| `data-mpc-episode-default-sort` | On the section: the editor's preselected order, applied to the `<select>` on load |
| `data-mpc-episode-sort` | The sort `<select>` |
| `data-mpc-episode-list` | The `<ol>` whose `<li>` children are sorted and paged |
| `data-mpc-episode-date` / `data-mpc-episode-sort-title` | Sort keys per row (`Y-m-d`, empty when unset) |
| `data-mpc-episode-paginate` | Pager container; carries `data-mpc-episode-per-page` and the pager labels |
| `data-mpc-episode-pager-nav` | Empty `<nav>` the buttons are rendered into |

### Downloads in the episode list

`episode.downloadUrl` and `episode.downloadInfo` ("MP3, 7.4 MB") are filled for
records with **Allow download** — but only in the *Episode list* layout of a
playlist. Every episode is on the page there, so a link per row lets visitors
save any of them without selecting it first.

Every other combination leaves the download to the player's own control-bar
button, which resolves its file from the selected playlist track
(`track.downloadUrl` / `downloadFormat` / `downloadFileSize`, see
`DownloadResolver::enrichTrack()`). That is also why *Episode
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

### Long description of a medium

`long_description` is the medium's RTE text. The detail page prints it in full;
the card and every list row offer it as a disclosure instead, so a long episode
list stays scannable. The button appears for records that have the field filled
— there is no editor switch for it.

Both halves are server-rendered: the panel ships collapsed with the `hidden`
attribute, and `EpisodeInit.js` only flips `hidden`, `aria-expanded` and the
button label. The panel carries no `id` and the button no `aria-controls`: the
now-playing card is a clone of a list row, so an id here would be in the
document twice as soon as a track is selected. `aria-expanded` on a button that
is immediately followed by its panel expresses the same relation without that
risk, which is also why the panel is looked up within the button's
`mpc-episode-body` rather than by reference.

| Attribute | Purpose |
|-----------|---------|
| `data-mpc-episode-longdesc-toggle` | The disclosure button. Carries `data-mpc-episode-label-more` / `-less` for the two states and `data-mpc-episode-title` for the accessible name |
| `data-mpc-episode-longdesc` | The panel, collapsed through the `hidden` attribute |

Inside a row the button **and** the panel need a `z-index` above the play
overlay. Without it the row-wide play target swallows the clicks, and the
expanded text can neither be selected nor followed into a link.

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
covers the row's text, so selecting a row's short description with the mouse is
not possible — the same trade-off the teasers make. The expanded long
description is the exception: it is lifted above the overlay and stays
selectable.

## Benefits

- **Single Responsibility** - Each partial has one clear purpose
- **Reusability** - Tracks partial shared by video/audio
- **Easy Override** - Customize only what you need
- **Maintainability** - Changes isolated to specific files
- **Readability** - Main template reduced from 250 to 130 lines  

## No Breaking Changes

Refactoring maintains identical HTML output and functionality. Existing configurations work without modification.
