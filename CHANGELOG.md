# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Convert SRT captions to WebVTT on save and via an upgrade wizard.

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

[Unreleased]: https://github.com/MatthiasPeltzer/mpc-vidply/compare/v1.2.4...HEAD
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
