# Streaming Conversion with ffmpeg (HLS and DASH)

VidPly can convert uploaded videos into adaptive streams on the server. The editor ticks
**Convert to stream (HLS/DASH)** on a media record, picks a format, and a scheduler task
encodes the video with ffmpeg. The result is a **separate media record "<title> (Stream)"**
in the same folder — the original record and its files stay unchanged.

> **Related:** [HLS Implementation](HLS-Implementation.md) (playback, CSP) · [Settings Architecture](SettingsArchitecture.md) · [Editors Guide](Editors-Guide.md#streaming-conversion-hls--dash)

---

## What gets converted

| Source on the original record | Streaming record gets | Fallback in the player |
|-------------------------------|-----------------------|------------------------|
| Main video (MP4, WebM, MOV, MKV) | HLS and/or DASH manifest + segments | Original MP4 (also used for **Allow download**) |
| Audio-description videos | HLS and/or DASH, own sub folder | Original audio-description MP4 |
| Sign-language videos | HLS and/or DASH **without audio** + an MP4 (CRF 26, no audio) | The generated MP4 |
| Poster | Copied | — |
| No poster | A frame at 10 % of the duration (`<name>-poster.jpg`, title as alternative text) | — |

Captions, chapters, the player options (transcript, download, speed/help buttons, floating
player, audio-description and sign-language modes), metadata (artist, descriptions,
duration, publish date, episode number) and **all translations** are mirrored to the
streaming record. Visibility, categories and the slug stay the editor's.
Change them on the original: every conversion rebuilds the streaming record from it.

External sources (YouTube, Vimeo, SoundCloud, linked URLs) and records that already
point to a stream cannot be converted.

## Formats

| Format | Manifest | Plays with |
|--------|----------|------------|
| **HLS** (default, recommended) | `<name>.m3u8` | Safari natively, hls.js everywhere else |
| **DASH** | `<name>.mpd` | dash.js (Safari on iOS has no MSE before 17.1) |
| **HLS and DASH** | both | One set of fMP4 (CMAF) segments served from both manifests — no double storage |

All formats write H.264 (High, yuv420p) video with stereo AAC audio (128 kbit/s) in fMP4
segments, keyframes forced on segment boundaries. The rendition ladder never upscales:
heights above the source height are skipped.

| Height | Video bitrate (kbit/s) |
|--------|------------------------|
| 1080p | 4950 |
| 720p | 2700 |
| 480p | 1450 |
| 360p | 950 |

## Output layout

```
<target folder>/<video-file-slug>-<uid>/
    <video-file-slug>.m3u8 / .mpd          main manifests
    <video-file-slug>-poster.jpg           only when the original has no poster
    …segments…
    ad-<file-slug>/                        one folder per audio-description video
    sign-<file-slug>/                      one folder per sign-language video
        <file-slug>-480p.mp4               progressive fallback
```

Manifests, fallback MP4s and the poster are indexed in FAL; segments are not (they would
only flood `sys_file`). A re-run replaces the folder and **keeps the `sys_file` uids** of the
indexed files on local storages, so references to the streaming record stay valid.

## Requirements

- **ffmpeg and ffprobe 7.1 or newer.** Debian/Ubuntu packages are often older — use a
  current static build (e.g. [BtbN/FFmpeg-Builds](https://github.com/BtbN/FFmpeg-Builds)
  `release` branch, GPL variant with libx264).
- **Scheduler** (`typo3/cms-scheduler`) or cron to run the conversion command.
- Enough disk space below `var/` (work directory) and in the target storage.
- Optional: **`typo3/cms-reports`** — VidPly then adds an *ffmpeg* entry to
  **System → Reports → Status** (found, version, minimum version).

### DDEV

The mpcore workspace ships `.ddev/web-build/Dockerfile.ffmpeg`, which installs the newest
stable BtbN static build (checksum-verified) into `/usr/local/bin`:

```bash
ddev debug rebuild && ddev restart   # pull the current stable release
ddev exec ffmpeg -version
```

Pin a release branch by setting `FFMPEG_VERSION` (e.g. `"8.0"`) in that file.

### Web server MIME types

`.m3u8` is known to nginx and Apache. Add the DASH types if you serve `.mpd`/`.m4s`:

```nginx
types {
    application/dash+xml mpd;
    video/iso.segment    m4s;
}
```

```apache
AddType application/dash+xml .mpd
AddType video/iso.segment .m4s
```

dash.js and Media Source Extensions also accept `application/octet-stream`, so this is
recommended rather than required.

## Setup

1. **Extension Configuration** → *mpc_vidply* → tab **streaming**:

   | Setting | Default | Description |
   |---------|---------|-------------|
   | `streamingEnabled` | `0` | Shows the conversion fields to editors and lets the command run |
   | `ffmpegPath` / `ffprobePath` | `ffmpeg` / `ffprobe` | Binary names in `PATH` or absolute paths |
   | `streamingThreads` | `0` | Encoder threads per run (`0` = ffmpeg decides) |
   | `streamingTargetFolder` | `1:/user_upload/` | Combined identifier of the target folder; created if missing |
   | `streamingRenditions` | `1080,720,480,360` | Heights of the main and audio-description renditions |
   | `streamingSignLanguageRenditions` | `480,360` | Heights of the sign-language renditions |
   | `streamingSegmentDuration` | `4` | Segment length in seconds |

   Changing the renditions or the segment duration re-queues already converted records
   the next time they are saved.

2. **Database:** run *Maintenance → Analyze Database Structure* (or
   `vendor/bin/typo3 extension:setup`) to add the `stream_*` fields.

3. **Scheduler:** add a task *Execute console commands* → `mpc-vidply:stream:convert`,
   recurring every few minutes. Options:

   | Option | Default | Description |
   |--------|---------|-------------|
   | `--limit=<n>` | `1` | Records to convert per run |
   | `--uid=<uid>` | — | Convert this record now, queued or not (it must have *Convert to stream* enabled) |

   A lock prevents two runs from encoding at the same time. A run that has not finished
   after six hours is considered dead and its record is queued again.

   From the shell:

   ```bash
   vendor/bin/typo3 mpc-vidply:stream:convert --limit=3
   ddev exec vendor/bin/typo3 mpc-vidply:stream:convert --uid=42
   ```

## Queue lifecycle

| Editor action | Result |
|---------------|--------|
| Save a video with *Convert to stream* on | Queued (`pending`) when never converted, failed, or the files, format or rendition settings changed since the last run |
| Scheduler run | `processing` → `done` (streaming record created or updated) or `failed` (ffmpeg error shown in the record) |
| Turn *Convert to stream* off | Streaming record and its output folder are removed |
| Delete the original | Streaming record and its output folder are removed |
| Delete the streaming record | *Convert to stream* is turned off on the original |
| Copy the original | The copy starts unconverted |

The **Streaming conversion** tab of the record shows the status, the last conversion date,
the ffmpeg version used, a link to the streaming record and, on failure, the last lines of
the ffmpeg output. The streaming record shows a notice that it is rebuilt from its original.

## Player behaviour

The streaming record renders like any HLS/DASH record (see
[HLS Implementation](HLS-Implementation.md)), plus fallbacks for the accessibility sources:

- The main video falls back to the original MP4 when the manifest cannot be loaded.
- Audio-description and sign-language streams are loaded through hls.js/dash.js (or
  natively in Safari) and fall back to their MP4 (`data-audio-description-fallback-src`,
  `data-sign-language-fallback-src`) when the stream fails.
- The sign-language overlay and the in-main-view display mode keep playing in sync with the
  main video across the switch.

## Troubleshooting

| Problem | Check |
|---------|-------|
| Fields not visible to editors | `streamingEnabled` is off |
| Record stays *queued* | Scheduler task missing or not running; run the command manually |
| *ffmpeg … cannot be run* | Path settings, `ddev exec ffmpeg -version`, Reports module |
| *ffmpeg … is too old* | Install 7.1+ (static build) |
| Streaming record has no translations | The folder must be inside a site whose configuration has those languages — TYPO3 only copies and localizes translations there |
| Stream plays, DASH fails in Safari on iOS | Choose HLS or *HLS and DASH* |
| Stream blocked in the browser | CSP `media-src` / `connect-src` — see [HLS Implementation](HLS-Implementation.md#content-security-policy) |
