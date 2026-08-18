# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.30] - 2026-08-18

### Changed
- Updated vendored dash.js to **5.2.1** (modern UMD).
- Ship VidPly player with live/VOD and stream-switch fixes

## [1.2.29] - 2026-08-16

### Added
- Updated bundled VidPly player: live stream controls (LIVE badge, conditional skip-forward, Go live, auto-detection for HLS/DASH live sources).
- Updated bundled VidPly player: HLS live subtitle/transcript fixes (auto-activate hls.js subtitle track, incremental live transcript, deduplicated cues).

### Fixed
- Updated bundled VidPly player: HLS VOD transcript loads all segmented WebVTT parts (not only the first ~6 s); control bar rebuild re-applies live/VOD button visibility after HLS caption buttons are added; VOD shows skip-forward again; live streams hide VOD-only controls pre-play and probe the level playlist on manifest parse for early live detection.
- Updated bundled VidPly player: VOD HLS sources no longer show the LIVE badge (Apple BipBop and similar demos); unknown duration before level playlists load no longer triggers live mode; VOD HLS no longer switches to live controls/captions when MSE reports `Infinity` duration during startup.

## [1.2.28] - 2026-08-16

### Accessibility
- Playlist privacy overlay: use semantic `<h2>` for headline instead of `role="heading"` on a paragraph.
- Detail page related shelf: label focusable shelf list via `aria-labelledby`.
- Media player partial: remove duplicate `aria-label` on `<video>`/`<audio>` when wrapper region is already labeled.
- Updated bundled VidPly player: transcript modal focus trap, caption style dialog trap, keyboard help inert background, slider `aria-orientation`, playlist `aria-selected`.

### Fixed
- URL import: respect the record media type when `.m3u8` URLs match both audio and video domain allow-lists — video records use the HLS helper, audio records keep external audio (fixes TV livestreams imported as audio-only).
- Player Options bitmask: align keyboard (bit 32) and auto-advance (bit 64) with TYPO3 FormEngine positional storage so backend saves match frontend behaviour (keyboard help button).
- Upgrade wizard and migration service remap legacy keyboard/auto-advance bits (64/256) and move show-track-info/resume flags into dedicated columns.
- Ship VidPly player fix for volume/mute init and scoped localStorage preferences.

## [1.2.27] - 2026-08-15

### Changed
- Updated bundled VidPly player assets (JS/CSS) to **v1.2.9** (hls.js **1.7.0** CDN pin and SRI in the HLS renderer).
- Updated vendored `hls.min.js` to **hls.js 1.7.0**.

## [1.2.26] - 2026-08-14

### Added
- Resume playback from the last position is off by default. Enable it site-wide
  via Site Management → Settings → VidPly Player, or per content element with
  the new “Resume from Last Position” player option.
- The player track-info header shows a collapsible long description when a media
  record has `long_description` filled in (default layout and single items).
  Card/episode layouts keep using the existing episode header instead.

### Changed
- Updated the bundled VidPly player: `resumePlayback` now defaults to off in the
  standalone player as well.

## [1.2.25] - 2026-08-11

### Changed
- Updated the bundled VidPly player assets to v1.2.7. The build carries no
  player behaviour changes over the previously shipped state — only the version
  banner and a rebuild with a newer bundler, which renamed every hashed chunk.

## [1.2.24] - 2026-08-10

### Fixed
- Shipped VidPly fix for YouTube (and Vimeo) playlist track switching: clicking
  another item in a multi-track external playlist now loads that video instead
  of keeping the first one playing.

## [1.2.23] - 2026-08-09

### Added
- The player's screen reader status announcements can be switched off per site
  without touching JavaScript. The `mpc/mpc-vidply` site set declares
  `mpcVidply.screenReaderAnnouncements` (default on), editable in Site
  Management → Sites → Settings → VidPly Player → Accessibility, in
  `config/sites/<id>/settings.yaml`, or readable in TypoScript as a constant.
  Announcements are on unless a site opts out, so nothing changes for existing
  installations.
- Episode card layout for the VidPly Player content element. A new **Layout**
  field (`tx_mpcvidply_layout`) offers *Player only* (unchanged default),
  *Episode card* and *Episode card with episode list*. The card renders a
  square cover with a play button next to episode number, title, publish date,
  duration and description, with the player below — laid out with intrinsic
  flex wrapping so it also fits narrow columns, sidebars and modals.
- *Episode card with episode list* now renders every selected medium below the
  player as episode cards, instead of only the first one. Each card's play button
  starts its own track in the shared player and doubles as its pause button; the
  list follows along when the track is changed from the player controls or when
  one episode runs into the next. The player's built-in playlist panel and its
  toggle button are suppressed for this layout, so the episode list is the
  single, server-rendered track list.
- The card above the player is the now-playing header in that layout: it shows
  the cover, title, publish date, duration, categories and description of the
  track in the player and follows every track change, so the layout opens with
  the same card as *Episode card* rather than a bare control bar.
- Episode rows are clickable across their whole area, not just on the play
  button, and show a hover and focus state — the same stretched-link approach the
  teasers use, so a row stays one control with one tab stop. Selecting a row's
  description text with the mouse is not possible as a result.
- The episode list has a sort dropdown (manual, date newest/oldest first, title
  A–Z) and pages itself once it holds more episodes than fit on a page. Three new
  editor fields set the defaults: **Episode order**
  (`tx_mpcvidply_episode_sort`), **Paginate episode list**
  (`tx_mpcvidply_episode_pagination`) and **Episodes per page**
  (`tx_mpcvidply_episode_per_page`, default 10). Both only reorder or hide rows —
  playback keeps the order of the media items, so next/previous and auto-advance
  are unaffected, and a track change reveals the page holding the active episode.
  Hidden rows use the `hidden` attribute, so they leave the tab order and the
  accessibility tree, and paging moves focus to the first row of the new page.
  Episodes without a publish date are listed last in both date orders.
- A medium's **Long description** is no longer limited to the detail page: in the
  *Episode card* layouts, records that have the field filled get a *Show
  description* button next to the download link that folds the rich text open
  below the episode. The panel is server-rendered and ships collapsed with the
  `hidden` attribute, so it costs no request and is found by the browser's page
  search; the button reports its state through `aria-expanded` and swaps its
  label between *Show description* and *Hide description*. Inside a list row the
  button and the expanded text sit above the row-wide play target, so the text
  stays selectable and links in it remain clickable.
- Listview cards in **grid** rows expose the same **Long description**: a button
  in the card's bottom-right corner opens it in a native popover, which the
  browser renders in the top layer, so the text is neither clipped by the card
  nor able to stretch the grid row's height. The button sits beside the card's
  stretched link instead of inside it, so no two controls are nested, and
  toggling, light dismiss, Escape and returning focus are handled by the browser
  without any JavaScript. Shelf rows are left alone — they clip vertical
  overflow and are meant for fast horizontal scanning — and browsers without the
  Popover API hide the button instead of printing the text into every card.
- **Allow download** now works in every layout, including playlists, where the
  setting used to be dropped silently. The player's control-bar button follows
  the selected track: it offers the file of whatever is playing, is labelled
  with that file's format and size, and disappears on tracks without the
  setting. In the *Episode card with episode list* layout the download sits on
  the episode itself instead ("Herunterladen MP3, 7,9 MB"), so any episode can
  be saved without selecting it first — leaving exactly one download affordance
  in either case. Sizes are measured on the storage rather than read from the
  file index, so a file replaced without re-indexing is not announced with its
  predecessor's size, and the same byte count is handed to the player instead of
  letting it measure the file again over the network.
- Media records have **Publish date** and **Episode number** fields. Dates are
  formatted for the site language in PHP (`IntlDateFormatter`, pinned to UTC
  because date-only fields are stored as midnight UTC) and handed to the
  player, which prints them verbatim in the playlist and now-playing panel.
- Structured data uses the publish date for `uploadDate` and adds
  `datePublished`; the record's creation date remains the fallback.

### Changed
- Updated the bundled VidPly player assets to include the new playlist date
  rendering, the optional big play button on audio players and the download
  button that resolves its file from the selected playlist track.
- A media record's **Audio description mode** is no longer ignored when the
  content element combines several media. Nothing switches that mode per track,
  so the first playable record now decides for the whole element instead of
  falling back to *auto*.
- The extension's own JavaScript is bundled with esbuild instead of minified
  file by file, so shared code can be reused between the playlist, episode,
  listview and privacy-layer scripts without adding a single HTTP request. CI
  rebuilds the assets and fails when the committed minified files are stale.
- Shared player, episode and listview styles (colour tokens, dark mode, the
  visually-hidden utility) live in one stylesheet that is prepended to each
  minified bundle, so the three files can no longer drift apart.
- The video and audio player partials were near-identical and are now one
  `MediaPlayer.html` parameterised by media kind. Overrides of
  `VidPly/VideoPlayer.html` or `VidPly/AudioPlayer.html` need to move to it.
- `VidPlyProcessor` is now orchestration only: track assembly, player options,
  the episode list, downloads, inline SVG, URL sanitizing and file lookups moved
  into dedicated services under `Classes/Service/Player/`. Extensions calling
  the processor's public API are unaffected; subclasses relying on its protected
  helpers are not.
- Duration formatting, detail URLs, file-reference prefetching and the MIME-type
  tables existed in up to four copies each and are now single services, as is
  the shared base of the four external-media OnlineMedia helpers and of the two
  translation-sync hooks.
- Listview rows are read through TYPO3's frontend restrictions instead of
  hand-written `deleted`/`hidden` checks, so hidden, scheduled and workspace
  records are treated exactly as they are for the media behind the rows. A
  workspace preview now shows the rows of that workspace.

### Fixed
- Screen readers announced the stored volume of every player on the page as
  soon as it loaded ("Lautstärke 53 Prozent", once per player), although
  nobody had touched a control. Fixed in the bundled player: the level is
  announced only when it really changes. The player's
  `screenReaderAnnouncements` option switches those status announcements off
  again as documented.
- The listview never initialised inside dynamically loaded content: sorting,
  row pagination and the card fade-in only ran on the initial page load, while
  the player, episode and privacy scripts already listened for
  `mpc:dynamic-content:ready`.
- Initialising a listview twice appended a second pagination bar to each row.
- Switching playlist tracks repeatedly stacked one autoplay listener per switch
  on the player.
- The configured play icon is validated before it is used as the image source of
  a playlist's privacy overlay, like everywhere else it is printed. The two
  copies of that check had drifted apart; the stricter one is now the only one.
- The player's keyboard help and settings dialogs were clipped to the height
  of the control bar on audio players, hiding their content. They now open as
  viewport-centered dialogs.
- Episode rows could point at the wrong track: media whose source could not be
  resolved were skipped when the playlist was built but still counted in the
  episode list, shifting every play button after them by one. The list is now
  built from the records that actually produced a track.
- An episode row's download link covered the player's keyboard help dialog. The
  row stacks its click overlay and its controls against each other, and those
  values competed with the player instead of staying inside the row; each row is
  now its own stacking context. The bundled player additionally lifts itself
  while a dialog is open, so page content cannot bury it.

### Accessibility
- The episode list is an ordered list whose heading names it and states the
  episode count. The selected row is marked with `aria-current`, and each play
  button carries its episode title in the accessible name and switches between
  *Play* and *Pause* as playback changes, so the state is announced rather than
  only shown by the icon.
- The download link names its episode in the accessible name, keeps a 24×24
  pointer target (WCAG 2.5.8) and sits above the row's stretched play overlay so
  it stays operable by mouse, keyboard and touch.
- Heading levels stay gapless when the now-playing card is swapped, and focus
  moves to the new play button if the card is replaced while the old one had
  focus — for example when one episode auto-advances into the next.
- The listview shelf arrows are no longer hidden from assistive technology: the
  wrapper carried `aria-hidden` around two focusable buttons, so keyboard users
  could reach controls screen readers could not see.
- The privacy layer's headline is a real `<h2>` instead of a paragraph with
  `role="heading"`.

## [1.2.22] - 2026-07-23

### Changed
- Updated the bundled VidPly player assets (JS/CSS) to the audit-remediation build v1.2.5

### Security
- Player now CSS-escapes poster URLs, scopes menu queries to the player container, and loads pinned hls.js/dash.js with default Subresource Integrity hashes.

### Accessibility
- Resume prompt now traps keyboard focus; drag/resize keyboard shortcuts no longer fire while typing in form controls.

## [1.2.21] - 2026-07-19

### Added
- Backend List module: **View** action for VidPly media records in storage folders opens the item on the configured detail page (slug or UID), including translated records.

### Fixed
- Media record preview failed silently because the detail-page lookup query used an invalid `innerJoin()` condition; preview now resolves detail pages inside the media storage folder before falling back site-wide.

## [1.2.20] - 2026-07-18

### Changed
- Detail view now sets the `description`, OpenGraph/Twitter `title`/`description` and `og:image`/`twitter:image` meta tags from the media element (title, description falling back to the long description, and the media poster) instead of the generic detail page record, matching the existing `<title>` override. Search snippets and social previews now show the media element.

### Tests
- Core-parity test infrastructure: Docker `Build/Scripts/runTests.sh`, multi-DB functional CI matrix, TYPO3 13/14 quality matrix, PHP/YAML/TypoScript linters, and Codeception acceptance smoke tests.
- Acceptance: add extension test that VidPly backend preview renders in the page module (seeded CE without media).
- Functional CI matrix reduced to sqlite/mariadb/mysql (drop PostgreSQL axis).

### Fixed
- Codeception acceptance: set `support_namespace: Support`, add Docker `--network-alias chrome`, and pass `TYPO3_PATH_*` to the acceptance PHP stack only (not the Codeception bootstrap container).
- Functional CI: require `typo3/testing-framework` ^9.3 for TYPO3 14; acceptance CI prefers Docker on GHA.
- PHPStan (TYPO3 13 matrix): guard poster imports on `Folder` before `createFile()`; ignore the runtime class-string `makeInstance()` calls used for the TYPO3 14 System Resource API.

## [1.2.19] - 2026-07-15

### Changed
- Remove per-extension `sort_xlf.php`;

### Tests
- Functional TCA bootstrap and showitem integrity tests for VidPly content elements and custom media tables.

### Fixed
- VidPly content elements: remove reference to non-existent `appearance` palette in backend form layout (use `frames` and `appearanceLinks` only).

## [1.2.18] - 2026-07-12

### Fixed
- Frontend category chips and detail-page categories now resolve connected
  `sys_category` translation overlays instead of always showing default-language titles.
- Listview row media/category selection now reads MM relations from the default-language
  row uid when a translated row overlay is active, so localized headlines no longer
  break row content resolution.
- Listview row headlines now resolve when localized child rows hang off the translated
  `tt_content` record (TYPO3's default inline-localization parent id).
- Auto-create localized `tx_mpcvidply_listview_row` records when a listview content
  element is translated or re-saved, so row headlines can be edited per language in
  the backend inline field and page-module preview.
- Frontend listview headlines on connected-mode pages now resolve translation overlays
  by `l10n_parent` when the default-language content element is rendered on a
  translated page.

### Changed
- Documentation: added doc hub (`Documentation/README.md`) and `Integrations.md`
  (Vue/Swiper, CSP, mp-core JSON-LD); corrected README install (Site Set),
  SoundCloud/download/floating-player editor guidance, extension configuration
  reference, and updated Developers Quickstart for v1.2.17.
- Documentation: removed emoji icons from `Developers-Quickstart.md` section headings;
  fixed internal anchor links to Structured data (JSON-LD).
- Documentation: embedded remaining backend screenshots; trimmed root README duplication;
  fixed cross-doc links; registered `documentation` path in `ext_emconf.php`.

## [1.2.17] - 2026-07-10

### Fixed
- PHPStan level 8 in backend preview renderers: normalize `GridColumnItem` records for
  TYPO3 v13 (array) and v14 (`RecordInterface`) without relying on `getRow()`.
- Shared `RecordAwareValueResolver` and `FrontendLanguageResolver` for cross-version
  FormEngine, preview, and DataProcessor code paths.
- CI job validating the extension against TYPO3 13.4 LTS.
- `ContentElementPreviewRecordTest` split by TYPO3 major version so mocks match
  `GridColumnItem::getRecord()` return types (array on v13, `RecordInterface` on v14).

## [1.2.16] - 2026-07-06

### Changed
- Adopt TYPO3 coding standards (`typo3/coding-standards`): `.php-cs-fixer.dist.php`, `.editorconfig`, and `composer cs` / `composer cs:fix` scripts.
- Declare `extra.typo3/cms.Package.providesPackages` in `composer.json` for TYPO3 v14.3 metadata.
- Enable SQLite for functional tests and switch PHP-CS-Fixer to `@PER-CS1x0`.

### Fixed
- Coding standards in `MediaImportAjaxController`.
- SRT → VTT caption migration failing with "Resource consistency check failed" on
  current TYPO3 13/14: align the file's MIME type with the `.vtt` extension before
  renaming so TYPO3's resource consistency check accepts the converted subtitle.

### Tests
- Remove obsolete `ReflectionMethod::setAccessible()` call in `MediaOEmbedMetadataServiceTest` (deprecated in PHP 8.5).

## [1.2.15] - 2026-07-04
- VidPly in Vue sliders/galleries: re-scan slide HTML after Swiper mounts (`window.VidPlyInit.scan`),
  listen for `mpc:dynamic-content:ready`, pause players on inactive slides, and catch up when
  the init script loads after Vue.

## [1.2.14] - 2026-07-01

### Added
- Per-media toggle **Hide keyboard shortcuts help** on `tx_mpcvidply_media` to hide the
  VidPly help button (`.vidply-help`) for a single item or per playlist track.

## [1.2.13] - 2026-06-30

### Fixed
- Add TYPO3 13 compatibility

## [1.2.12] - 2026-06-29

### Fixed
- Listview Page module preview on TYPO3 13.4: pass a comma-separated field list to
  `BackendUtility::getRecord()` instead of an array (array `$fields` is supported
  from TYPO3 14 only), which caused a 503 in the Page module.

## [1.2.11] - 2026-06-28

### Added
- **Import from URL** on VidPly Media records: paste a URL to auto-detect
  `media_type`, create the FAL online-media reference, and pre-fill title, artist,
  and poster from oEmbed/metadata where available.
- **Refresh metadata** button on existing online-media records to re-fetch provider
  metadata without overwriting manually edited fields.
- Backend services `MediaUrlNormalizer`, `MediaFromUrlService`, and AJAX endpoints
  `mpc_vidply_media_import_url` / `mpc_vidply_media_refresh_metadata`.

### Fixed
- Backend **Import from URL** applies the FAL reference, title, artist, and media type
  immediately in the form instead of relying on a reload that breaks new inline
  records (NEW… uid changes on save).
- Metadata import merges oEmbed data with FAL file properties so title, artist,
  description, duration, and poster populate the correct FormEngine fields (exact
  field-name targeting via `data-item-name`).
- Import no longer triggers an accidental FormEngine reload when setting `media_type`
  on new inline records (which cleared title and other metadata before save).
- YouTube imports enrich description and duration from watch-page metadata; poster
  falls back to oEmbed `thumbnail_url` when TYPO3 preview download fails.
- URL import creates poster FAL files via `createFile()` + `setContents()` in the
  online-media storage folder (replacing temp-file `addFile()` that returned
  `posterFileUid: 0` in the AJAX response).
- SoundCloud URL import enriches **description** from oEmbed and **duration**
  from the public track page when oEmbed does not expose length.
- Poster file relations attach via TYPO3 file field `data-object-group` lookup and
  insert only after the media file relation is in place.
- Imported poster images persist to the `poster` FAL field on record save when
  FormEngine could not attach them in the browser (separate from the online-media
  preview thumbnail on `media_file`).
- FAL inline object lookup scopes to the current media record panel and matches
  `typo3-formengine-container-inline` file fields.
- FormEngine session prefill resolves array-shaped `uid` values from FormEngine.
- Backend **Import from URL** module self-initializes on load (no `invoke()`) and is
  registered for FormEngine import maps (`backend.form` tag).

### Changed
- `MediaObjectJsonLdBuilder` reuses shared YouTube/Vimeo ID extraction from
  `MediaUrlNormalizer`.

## [1.2.10] - 2026-06-28

### Added
- Standalone `VideoObject` / `AudioObject` JSON-LD via mpc-vidply's own page header
  partial (`headerData.2042`); works on any site without mp-core.
- Optional mp-core integration: when mp-core is installed and structured data is
  enabled, the video node is merged into mp-core's `@graph` instead of a second script.
- `ItemList` JSON-LD when a page contains multiple distinct videos (several
  `mpc_vidply` players and/or playlists); single-video pages keep one `VideoObject`.
- Rich media schema fields: absolute `url`, `contentUrl`, `embedUrl`, `@id`,
  long-description fallback, and `WebPage.mainEntity` (mp-core merge path).
- `mpc_vidply_listview` shelves are now included in the structured data, deduplicated
  together with inline players by default-language media UID. A shared
  `ListviewMediaResolver` derives the same records for both the rendered cards and the
  JSON-LD.

### Changed
- Video JSON-LD covers inline `mpc_vidply` players and routed `mpc_vidply_detail`
  pages through a shared page-level resolver.
- Gallery and list items now resolve their media URLs through a lightweight context
  (`VidPlyProcessor::assembleStructuredDataContext()`) instead of assembling a full
  player per item, reducing the cost of structured data on pages with many media.
- Watch-URL resolution prefers a listview's configured detail page
  (`tx_mpcvidply_detail_page`), then a detail element on the same page, then the
  site-wide default; the on-page fallback fragment is now `#media-<uid>`.
- Structured-data `tt_content` lookups now honour TYPO3 frontend visibility
  restrictions (start/end time, access groups), matching the rest of the frontend.

### Fixed
- HLS/DASH manifests are no longer emitted as `contentUrl` (they are not progressive
  files eligible for video rich results); the `embedUrl` still links the watch page.
- When mp-core is loaded but produces no usable `@graph`, VidPly now falls back to a
  standalone JSON-LD document instead of emitting nothing.

### Tests
- Added unit coverage for the JSON-LD merge/standalone-fallback logic, the lightweight
  structured-data context guards, HLS `contentUrl` omission, and JSON encoding.

## [1.2.9] - 2026-06-27

### Fixed
- Show only helper functions that are needed

## [1.2.8] - 2026-06-27

### Added
- Updated the bundled VidPly player with an accessible keyboard-shortcuts help
  overlay: a focus-trapped dialog (reachable via a control-bar help button and
  the `?` shortcut) listing the active key bindings.
- Updated the bundled VidPly player with Media Session API support: OS /
  lock-screen / notification / headset controls and now-playing metadata.

### Fixed
- Updated the bundled VidPly player so that, on pages with multiple players,
  the OS media controls drive the player that is actually playing instead of a
  background instance (which made the controls appear but do nothing).

## [1.2.7] - 2026-06-24

### Fixed
- Re-added the nonce (`nonceProxy`) to the `script-src-elem` and `style-src-elem`
  CSP mutations. Extending these `-elem` directives materialises them in the
  final policy, where they override `script-src`/`style-src` for inline
  `<script>`/`<style>` elements. Since mp-core attaches the nonce only to
  `script-src`/`style-src`, nonce'd inline blocks (site-config colours, web
  fonts, theme bootstrap, structured data) were being blocked by
  `style-src-elem`/`script-src-elem` on pages that include VidPly.

## [1.2.6] - 2026-06-24

### Security
- Hardened the Content Security Policy: dropped `style-src`/`style-src-elem`
  `'unsafe-inline'` and removed the unused `cdn.jsdelivr.net` script origin
  (hls.js/dash.js are vendored locally). Frontend templates no longer emit
  inline `style` attributes; the poster background and custom play-icon are now
  applied at runtime via `element.style`.

### Fixed
- `MediaRepository::findByCategories()` now groups by every selected column and
  aggregates the manual sort position, so category listings work under MySQL/
  MariaDB `ONLY_FULL_GROUP_BY` (previously failed with an SQL error).
- Render the non-RTE media `description` on the detail view as escaped text with
  preserved line breaks (`f:format.nl2br`) instead of `f:format.html`.
- Corrected the source-detection guard in `PlaylistInit.js` so the `<source>`
  child re-scan runs only when the stream type was not already determined.
- Fixed the privacy-overlay button `aria-label` showing a literal `{0}`
  placeholder: the server-rendered fallback now uses per-service translation
  keys (`privacy.load_content.{youtube,vimeo,soundcloud}`) matching the
  JS-rendered overlay, instead of a `{0}` placeholder that `f:translate` could
  not substitute.

### Accessibility
- Moved the privacy-overlay headline out of the surrounding `<p>` into its own
  element in both the server-rendered and JS-rendered overlays, fixing the
  invalid `role="heading"` inside a paragraph.

### Tests
- Introduced a PHPUnit unit-test suite (`Tests/Unit`, `phpunit.xml.dist`) with
  initial coverage for `SrtToVttConverter`, the external-media domain-validation
  trait, and the CSS-URL/SVG sanitizers.
- Expanded the unit suite to cover the `Dto/*` result objects, the `MediaType`/
  `RenderMode` enums, all five OnlineMedia helpers (`transformUrlToFile()` URL
  validation and `getMetaData()` parsing), the streaming CSP event listener, and
  the pure decision/mapping helpers of `VidPlyProcessor`, plus more
  `SrtToVttConverter` edge cases (CRLF, multi-line cues, ISO-8859-1 fallback).
- Added a `typo3/testing-framework` functional suite (`Tests/Functional`,
  `Build/FunctionalTests.xml`, `composer test:functional`) running against a real
  database. Covers `MediaRepository` (MM ordering, language overlay, slug/uid,
  categories, access conditions), `DetailRequestResolver`,
  `PrivacySettingsService`, `SrtCaptionMigrationService` (FAL conversion),
  `VidPlyProcessor` assembly, and the `ConvertSrtCaptions` command.

## [1.2.5] - 2026-06-22

### Added
- Convert SRT captions to WebVTT on save and via an upgrade wizard.
- Add Changelog

## [1.2.4] - 2026-06-14

### Added
- `audio_description_mode` TCA field and processor mapping.
- Documentation for `audio_description_mode`.

## [1.2.3] - 2026-06-14

### Changed
- Decoupled mpc-vidply from `fluid_styled_content` and `mp_core`.
- Extend `connect-src` via `PolicyMutatedEvent` for HLS/DASH.

## [1.2.2] - 2026-06-13

### Changed
- Added `.editorconfig`.
- Enforce LF line endings via `.gitattributes`.

## [1.2.1] - 2026-06-04

### Changed
- Lazy-load audio-description and sign-language chunks only when their content is present.

## [1.2.0] - 2026-06-04

### Changed
- Code-review hardening: accessibility, memory-leak, bundle-size and type-safety fixes.

## [1.1.17] - 2026-05-29

### Changed
- Updated vendored streaming libraries to hls.js 1.6.16 and dash.js 5.2.0.

## [1.1.16] - 2026-05-24

### Added
- No-JS optimization: render video/audio without the player when JavaScript is unavailable.

## [1.1.15] - 2026-05-18

### Changed
- Updated the bundled vidply player to v1.1.18.

## [1.1.14] - 2026-05-17

### Changed
- Removed deprecations.

## [1.1.13] - 2026-05-07

### Fixed
- Show the page title in the detail view of a media item.

## [1.1.12] - 2026-05-05

### Changed
- Updated the bundled vidply player.
- Keep the transcript out of the mini-player and decorate the vacated slot with a centred PiP glyph.
- Dropped ~2.1k lines of dead legacy code and plugged document/window listener leaks (-18% ESM bundle); sign-language mode badges are now translatable DOM nodes.

## [1.1.11] - 2026-05-03

### Changed
- Updated the bundled vidply player: security/accessibility/quality audit, full transcript, HLS captions and endonym labels.

### Fixed
- Mixed-playlist handling.

## [1.1.10] - 2026-04-26

### Added
- New media-library list-view content element moved to plugins: shelves, grid, localization, sorting, categories, optional pagination and detail copy.

## [1.1.9] - 2026-04-25

### Fixed
- DASH renderer: predictable initial audio-track selection.
- Pinned dash.js to 5.1.1.
- Console errors with preview images in HLS and DASH.
- Show captions and `lang` attributes on the language switcher.

## [1.1.8] - 2026-04-25

### Changed
- Updated dash.js and hls.js dependencies to the latest versions.

## [1.1.7] - 2026-04-23

### Changed
- Maintenance release (version bump only).

## [1.1.5] - 2026-04-21

### Fixed
- Resize handling for the floating/PiP player.

## [1.1.4] - 2026-04-21

### Added
- `FloatingPlayerManager` / PiP player module.
- Show file format and size on the download button label/tooltip.

## [1.1.3] - 2026-04-19

### Changed
- Refreshed documentation (TypeScript migration, SoundCloud renderer, buffering spinner, download button, native iOS HLS bridge).

### Fixed
- Sync the hls.js subtitle track on language switch and listen for `textcuesupdate`.

## [1.1.2] - 2026-04-17

### Fixed
- Limit image formats for poster images.
- Populate the HLS transcript by allowing `data:` in `media-src` CSP and listening to `SUBTITLE_FRAG_PROCESSED`; WCAG, security and code-quality hardening.

## [1.1.1] - 2026-04-16

### Added
- Buffering spinner.

## [1.1.0] - 2026-04-16

### Changed
- Converted ES modules to strict TypeScript; updated build and type definitions.

### Added
- Buffering spinner.

## [1.0.54] - 2026-04-14

### Changed
- Unified media types — folded HLS/DASH into video/audio.
- Updated documentation to reflect the unified media types and DASH.

## [1.0.53] - 2026-04-12

### Added
- MPEG-DASH streaming support.

### Changed
- Updated the bundled vidply player to 1.0.50.

## [1.0.51] - 2026-04-10

### Changed
- Code audit: HLS metadata now shows captions/transcripts, minified CSS, updated the database schema and removed unused code.

### Security
- Security fixes.

## [1.0.50] - 2026-04-03

### Changed
- Updated the README.

## [1.0.49] - 2026-04-03

### Fixed
- Volume dot.

## [1.0.48] - 2026-04-03

### Accessibility
- WCAG 2.2 AA compliance: target size and dragging movements.

## [1.0.47] - 2026-03-27

### Changed
- npm terser update.

### Fixed
- Duration display and seek-forward in Firefox when the duration field is empty.
- Audio-description source init timing; demo pages use `data-vidply` auto-init.

## [1.0.46] - 2026-03-11

### Changed
- Decomposed `VidPlyProcessor`, added a `MediaType` enum and flattened Fluid template branching.
- Extracted `MediaRepository`, added a `RenderMode` enum; added German translations and sorted translation files.

### Fixed
- TYPO3 14 compatibility issues.
- Deduplicated OnlineMedia helpers; fixed a QueryBuilder reuse bug.

### Security
- Hardened the Content Security Policy.

## [1.0.45] - 2026-02-22

### Fixed
- Set duration on initial load.

## [1.0.44] - 2026-02-21

### Added
- Dark/light themes.

### Changed
- Improved loading speed for HLS and playlists.

## [1.0.43] - 2026-02-12

### Fixed
- Transcript dialog overflow for full-width video players.

## [1.0.42] - 2026-02-09

### Fixed
- Removed the browser fallback message.

## [1.0.41] - 2026-02-09

### Changed
- Updated hls.js to 1.6.15.

### Fixed
- HTML5 validation errors for accessibility and media elements.

## [1.0.40] - 2026-02-01

### Fixed
- Sign-language display mode and new PiP icon (playlist fix).

## [1.0.39] - 2026-02-01

### Fixed
- Sign-language display mode and new PiP icon (JS location fix).

## [1.0.38] - 2026-02-01

### Added
- Sign-language display-mode backend option.

### Changed
- npm terser update.

## [1.0.37] - 2026-01-11

### Fixed
- Play icon options.

## [1.0.36] - 2026-01-10

### Added
- CSS-based icon system with theme support.
- Configurable individual playlist button in site settings.

### Fixed
- Comprehensive fullscreen mode improvements.

## [1.0.35] - 2026-01-06

### Fixed
- Mixed playlist: hide audio-track artwork on external tracks.

## [1.0.34] - 2026-01-05

### Fixed
- Removed doubled frame classes; fixed aspect-ratio bugs in the vidply CSS/JS.

## [1.0.33] - 2026-01-01

### Changed
- Updated the bundled vidply player: audio posters in mixed playlists, terser JS minification, always responsive (no fixed height/width).

## [1.0.32] - 2025-12-27

### Added
- Playlist selection loads metadata without autoplay; shows initial duration; defers loading audio/video/HLS files until play.

### Fixed
- Set `searchable => false` for TYPO3 14.

### Changed
- Updated documentation.

## [1.0.31] - 2025-12-25

### Added
- SoundCloud as FAL online media (file-only) with thumbnails.
- Standalone external MP3/MP4 support (helpers, allowlists, previews); batch-load media records and FAL relations in `VidPlyProcessor`.

### Fixed
- Hide the speed control for HLS streams (detected by `.m3u8` source).

## [1.0.30] - 2025-12-23

### Fixed
- Removed unnecessary backend tabs for YouTube and Vimeo videos.

## [1.0.29] - 2025-12-23

### Added
- Site-wide privacy-layer settings with backend configuration.

## [1.0.28] - 2025-12-23

### Added
- Site-wide privacy-layer settings with backend configuration.

## [1.0.27] - 2025-12-23

### Added
- Site-wide privacy-layer settings with backend configuration.

## [1.0.26] - 2025-12-22

### Added
- Video preview thumbnails and auto-generated posters.

## [1.0.25] - 2025-12-21

### Fixed
- TYPO3 14 TCA migrations and deprecation fixes.

## [1.0.24] - 2025-12-16

### Fixed
- Enable language synchronization for media items on translation.

## [1.0.23] - 2025-12-16

### Added
- Support for audio-description VTT tracks (`data-desc-src`); updated the vidply libraries.

## [1.0.22] - 2025-12-15

### Fixed
- Added a missing translation inside playlists.

## [1.0.21] - 2025-12-15

### Changed
- Optimized and minified JS files.

## [1.0.20] - 2025-12-14

### Added
- Mixed playlist for video, audio and HLS plus YouTube/Vimeo/SoundCloud, with privacy consent, i18n and autoplay.

## [1.0.19] - 2025-12-12

### Fixed
- vidply language-detection lazy-loading.

## [1.0.18] - 2025-12-11

### Changed
- ESM optimization.

## [1.0.17] - 2025-12-10

### Added
- Multi-language support for player items.

## [1.0.16] - 2025-12-10

### Added
- `scrollIntoView` for keyboard navigation in all menu popups.
- Description, duration and audio-description duration in the frontend playlists.

## [1.0.15] - 2025-12-09

### Fixed
- Removed the quality switch and unnecessary backend fields; restored the missing quality switch.

## [1.0.14] - 2025-12-07

### Changed
- Applied TCA migrations for TYPO3 13+ and made mpc-vidply independent from mp-core.

## [1.0.13] - 2025-12-05

### Changed
- Corrected the version numbering to the 1.0.x scheme; updated composer description and extension requirements.

## [1.0.12] - 2025-12-04

### Fixed
- composer configuration.

## [1.0.11] - 2025-12-03

### Fixed
- Removed the default layout and an unneeded doubled header.

## [1.0.10] - 2025-12-03

### Fixed
- TYPO3 14 compatibility: handle record objects in preview renderers.

## [1.0.9] - 2025-12-02

### Changed
- Use rem units for the vidply player.

## [1.0.8] - 2025-12-01

### Added
- Bundled `hls.min.js` in the repository to avoid external sources.

### Fixed
- Removed debug messages; prevented double-loading of `vidply.esm.min.js`.

## [1.0.7] - 2025-12-01

### Added
- Optional poster image for audio in single and playlist mode.

## [1.0.6] - 2025-11-30

### Fixed
- Added `.gitattributes` so the `Configuration` directory is included in the Packagist archive.

## [1.0.5] - 2025-11-30

### Added
- License.

## [1.0.4] - 2025-11-30

### Fixed
- Packagist webhook response parsing.

## [1.0.3] - 2025-11-30

### Added
- Packagist webhook with error handling.

## [1.0.2] - 2025-11-30

### Changed
- Use the Packagist GitHub Action for reliability.

## [1.0.1] - 2025-11-29

### Security
- Fixed a Content Security Policy problem with SoundCloud.

## [1.0.0] - 2025-11-29

- Initial release.

[1.2.30]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.29...v1.2.30
[1.2.29]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.28...v1.2.29
[1.2.28]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.27...v1.2.28
[1.2.27]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.26...v1.2.27
[1.2.26]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.25...v1.2.26
[1.2.25]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.24...v1.2.25
[1.2.24]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.23...v1.2.24
[1.2.23]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.22...v1.2.23
[1.2.22]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.21...v1.2.22
[1.2.21]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.20...v1.2.21
[1.2.20]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.19...v1.2.20
[1.2.19]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.18...v1.2.19
[1.2.18]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.17...v1.2.18
[1.2.17]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.16...v1.2.17
[1.2.16]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.15...v1.2.16
[1.2.15]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.14...v1.2.15
[1.2.14]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.13...v1.2.14
[1.2.13]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.12...v1.2.13
[1.2.12]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.11...v1.2.12
[1.2.11]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.10...v1.2.11
[1.2.10]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.9...v1.2.10
[1.2.9]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.8...v1.2.9
[1.2.8]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.7...v1.2.8
[1.2.7]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.6...v1.2.7
[1.2.6]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.5...v1.2.6
[1.2.5]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.4...v1.2.5
[1.2.4]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.3...v1.2.4
[1.2.3]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.17...v1.2.0
[1.1.17]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.16...v1.1.17
[1.1.16]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.15...v1.1.16
[1.1.15]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.14...v1.1.15
[1.1.14]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.13...v1.1.14
[1.1.13]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.12...v1.1.13
[1.1.12]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.11...v1.1.12
[1.1.11]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.10...v1.1.11
[1.1.10]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.9...v1.1.10
[1.1.9]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.8...v1.1.9
[1.1.8]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.7...v1.1.8
[1.1.7]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.5...v1.1.7
[1.1.5]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.4...v1.1.5
[1.1.4]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.54...v1.1.0
[1.0.54]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.53...v1.0.54
[1.0.53]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.51...v1.0.53
[1.0.51]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.50...v1.0.51
[1.0.50]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.49...v1.0.50
[1.0.49]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.48...v1.0.49
[1.0.48]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.47...v1.0.48
[1.0.47]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.46...v1.0.47
[1.0.46]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.45...v1.0.46
[1.0.45]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.44...v1.0.45
[1.0.44]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.43...v1.0.44
[1.0.43]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.42...v1.0.43
[1.0.42]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.41...v1.0.42
[1.0.41]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.40...v1.0.41
[1.0.40]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.39...v1.0.40
[1.0.39]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.38...v1.0.39
[1.0.38]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.37...v1.0.38
[1.0.37]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.36...v1.0.37
[1.0.36]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.35...v1.0.36
[1.0.35]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.34...v1.0.35
[1.0.34]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.33...v1.0.34
[1.0.33]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.32...v1.0.33
[1.0.32]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.31...v1.0.32
[1.0.31]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.30...v1.0.31
[1.0.30]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.29...v1.0.30
[1.0.29]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.28...v1.0.29
[1.0.28]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.27...v1.0.28
[1.0.27]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.26...v1.0.27
[1.0.26]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.25...v1.0.26
[1.0.25]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.24...v1.0.25
[1.0.24]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.23...v1.0.24
[1.0.23]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.22...v1.0.23
[1.0.22]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.21...v1.0.22
[1.0.21]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.20...v1.0.21
[1.0.20]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.19...v1.0.20
[1.0.19]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.18...v1.0.19
[1.0.18]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.17...v1.0.18
[1.0.17]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.16...v1.0.17
[1.0.16]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.15...v1.0.16
[1.0.15]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.14...v1.0.15
[1.0.14]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.13...v1.0.14
[1.0.13]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.12...v1.0.13
[1.0.12]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.11...v1.0.12
[1.0.11]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.10...v1.0.11
[1.0.10]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.9...v1.0.10
[1.0.9]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.8...v1.0.9
[1.0.8]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.7...v1.0.8
[1.0.7]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.6...v1.0.7
[1.0.6]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/MatthiasPeltzer/mpc-vidply/releases/tag/v1.0.0
