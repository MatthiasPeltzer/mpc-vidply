# Editor's Guide: VidPly Player

A practical guide for content editors on how to use VidPly to add accessible video and audio content.

---

## Quick Overview

VidPly is a universal, accessible media player supporting multiple sources:

| Media Type | Source | Privacy Layer | Best For |
|------------|--------|---------------|----------|
| **Video** | Upload MP4/WebM + HLS/DASH | No | Local & streaming video |
| **Audio** | Upload MP3/OGG + HLS/DASH | No | Podcasts, music, streaming |
| **YouTube** | Video URL | Yes (GDPR) | External videos |
| **Vimeo** | Video URL | Yes (GDPR) | Professional videos |
| **SoundCloud** | Track/Set URL | Yes (GDPR) | Music, podcasts |

---

## Two-Step Workflow

### Step 1: Create Media Records

First, create reusable media items in the **VidPly Media** storage.

### Step 2: Add Player to Page

Then, add a **VidPly Player** content element and select your media.

---

## Step 1: Creating Media Records

### Where to Find It

**Web → List Module → Select storage folder → Click "+" → VidPly Media**

Or use the "Create new record" button in the VidPly Player element.

### Media Types Explained

#### Video

**Use for:** Self-hosted video files, HLS/DASH streaming with optional progressive fallbacks

**Supported formats:** MP4, WebM, HLS (.m3u8), DASH (.mpd)

**How to create:**
1. Select media type: **Video**
2. Click "Add media file" and upload your video or add a streaming URL (.m3u8 / .mpd)
3. Optionally add additional files as fallbacks (e.g. DASH + HLS + MP4)
4. Add a **title** (required)
5. Add a **poster image** (thumbnail shown before playback) — **allowed formats: JPG / JPEG, PNG, WebP, SVG**

**Tips:**
- Upload multiple formats for browser compatibility and fallback (DASH → HLS → MP4/WebM)
- Recommended: MP4 with H.264 codec as progressive fallback
- HLS/DASH sources use embedded captions by default; local VTT files can override them
- HLS streams play through `hls.js` on Chrome / Firefox / Edge / desktop Safari and through native HLS on iOS / iPadOS — captions, transcript and quality controls work on all platforms
- Keep file sizes reasonable (compress for web)

---

#### Audio

**Use for:** Podcasts, music, audio content, audio streaming

**Supported formats:** MP3, OGG, HLS (.m3u8), DASH (.mpd)

**How to create:**
1. Select media type: **Audio**
2. Click "Add media file" and upload your audio or add a streaming URL (.m3u8 / .mpd)
3. Optionally add additional files as fallbacks (e.g. DASH + HLS + MP3)
4. Add a **title** (required)
5. Optionally add a **poster image** (album art) — **allowed formats: JPG / JPEG, PNG, WebP, SVG**

---

#### YouTube

**Use for:** YouTube videos with GDPR-compliant privacy layer

**How to create:**
1. Select media type: **YouTube**
2. Click "Add media file" → "Add external video"
3. Paste the YouTube URL (e.g., `https://www.youtube.com/watch?v=...`)
4. Add a **title** (required)
5. Add a **poster image** (shown before consent) — **allowed formats: JPG / JPEG, PNG, WebP, SVG**

**Privacy behavior:**
- Video does NOT load until user clicks play
- Shows privacy notice overlay
- No tracking before user consent
- Compliant with GDPR requirements

---

#### Vimeo

**Use for:** Vimeo videos with privacy layer

**How to create:**
1. Select media type: **Vimeo**
2. Click "Add media file" → "Add external video"
3. Paste the Vimeo URL (e.g., `https://vimeo.com/123456789`)
4. Add a **title** (required)
5. Add a **poster image** — **allowed formats: JPG / JPEG, PNG, WebP, SVG**

---

#### SoundCloud

**Use for:** SoundCloud tracks or playlists with privacy layer

**How to create:**
1. Select media type: **SoundCloud**
2. Enter the **Media URL** (track or set URL)
3. Add a **title** (required)
4. Add a **poster image** (optional) — **allowed formats: JPG / JPEG, PNG, WebP, SVG**

> SoundCloud combines a **GDPR consent overlay** (no tracking before the user accepts) with the dedicated VidPly **SoundCloud renderer** that takes over after consent — playback, seek, volume, transcript and the rest of the VidPly UI behave the same as for YouTube and Vimeo.

---

### Adding Metadata

Every media record has a **metadata palette**:

| Field | Description | Required |
|-------|-------------|----------|
| **Title** | Display name in player/playlist | Yes |
| **Artist** | Creator name (shown in playlist) | No |
| **Description** | Short text (list cards, player context) | No |
| **Long description** | Rich text (CKEditor); shown on the **VidPly Detail** page below the short description | No |
| **Duration** | Length in seconds (for display) | No |
| **Poster** | Thumbnail image | Recommended |

### VidPly Listview & detail page (overview)

To build an **Mediathek-style** overview: add a **VidPly Listview**
content element, pick a **detail** page, and add one or more **Listview
rows** (shelf or grid, manual or category-based selection). Optional: turn on
**client-side pagination** and set **items per page** per row, use the
in-browser **Sort** control on the public site, and keep shelf definitions on
the default language when the site is translated. See
[`Listview.md`](Listview.md) for step-by-step setup, URLs, and administrator
notes.

**VidPly Detail** (the content element on the media detail page) has a
**Related content** option under *Settings* (on by default). Turn it off if you
do not want the “You might also like” row below the player.

---

### Player UI Options (per media record)

- **Hide speed button**: Hides the playback speed control for this media item.
  - **Single item**: the speed control is hidden.
  - **Playlist**: the speed control is hidden **only while this item is the active track** (it can re-appear for other tracks).
- **Buffering spinner**: A centered loading spinner is shown automatically while the player is buffering — no configuration required. Works for local files, HLS and DASH streams.
- **Download button** *(if exposed by your sitepackage / TCA override)*: Per-media toggle to show a download button in the control bar. For HLS / DASH sources, also configure an explicit download URL pointing to a single MP4 / MP3 / WebM file (manifests are not directly downloadable).

## Accessibility Features

VidPly provides WCAG 2.2 AA accessibility support.

### Captions & Subtitles

**Tab: Captions**

Add WebVTT (`.vtt`) or SubRip (`.srt`) files for viewers who need captions (including many deaf and hard-of-hearing users):

1. Click "Add captions file"
2. Upload your `.vtt` or `.srt` file
3. Set **Track kind**: Captions or Subtitles
4. Set **Language name**: e.g., "English", "Deutsch"
5. Set **Language code**: e.g., "en", "de"
6. **Save the media record** — SRT files are converted automatically to WebVTT; you will see a backend notification when conversion succeeds

**Multiple languages:** Add multiple caption files for each language.

**SRT uploads:** SubRip (`.srt`) is accepted for convenience. VidPly stores WebVTT internally because browsers and the transcript feature require it. The original SRT file is replaced on save.

**Legacy SRT files already in the file storage:** Ask a site administrator to run the one-time upgrade wizard **Admin Tools → Upgrade → Upgrade Wizards → VidPly: Convert SRT caption files to WebVTT** (no command line required).

**VTT Example:**
```
WEBVTT

00:00:00.000 --> 00:00:03.000
Welcome to our video tutorial.

00:00:03.000 --> 00:00:07.000
Today we'll learn about VidPly.
```

---

### Chapters

**Tab: Captions → Chapters**

Add chapter markers for easy navigation:

1. Click "Add chapters file"
2. Upload your chapters `.vtt` or `.srt` file (SRT is converted to WebVTT when you save the media record)
3. Set **Track kind**: Chapters
4. Set language info

**Chapters VTT Example:**
```
WEBVTT

00:00:00.000 --> 00:02:30.000
Introduction

00:02:30.000 --> 00:08:00.000
Main Content

00:08:00.000 --> 00:10:00.000
Conclusion
```

---

### Audio Description

**Tab: Accessibility → Audio Description**

VidPly supports two spoken audio-description workflows plus text descriptions in the transcript.

#### Option A — Described video (recommended)

For blind and low-vision users, add a video with narrated descriptions already mixed into the audio:

1. Click **Add audio description**
2. Upload an alternative **MP4 or WebM** with embedded narration
3. Users toggle between standard and described video via the AD button

Optionally set **Audio Description Duration** when the described version is longer than the original.

#### Option B — VTT speech (extended AD, no described video)

When you do not have a described video file:

1. Under **Captions**, upload a WebVTT file and set track kind to **Descriptions**
2. Leave **Audio Description** empty (or set mode explicitly — see below)
3. With **Audio Description Mode** set to **Automatic** or **Descriptions VTT speech**, VidPly pauses the video at each description cue, speaks the text via the browser’s speech synthesis, then resumes playback

Text description cues remain visible in the **transcript panel** for users who prefer reading. This path requires browser `speechSynthesis` support; voice quality varies by browser.

#### Audio Description Mode

**Tab: Accessibility → Audio Description Mode**

| Mode | Behavior |
|------|----------|
| **Automatic** (default) | Use described video if uploaded; otherwise VTT speech if a Descriptions VTT exists |
| **Described video only** | Swap to described MP4/WebM only; ignore VTT speech |
| **Descriptions VTT speech** | Speak descriptions VTT cues only; do not swap video |

#### Alternate caption/chapter files for described video

When using described video swap, you can attach an **Audio Description Version** VTT per caption or chapter file reference (`tx_desc_src_file`). Those alternate files load when AD mode is active.

---

### Sign Language

**Tab: Accessibility → Sign Language**

Add sign language interpretation overlay:

1. Click "Add sign language video"
2. Upload video with sign language interpreter
3. Appears as picture-in-picture overlay

---

### Transcripts

**Tab: Captions → Enable Transcript**

Generate searchable text transcript from captions:

1. Enable "Show transcript panel"
2. Captions are displayed as clickable, searchable text
3. Users can click any line to jump to that point

---

## Step 2: Adding Player to Page

### Insert Content Element

**Page Module → Add Content → VidPly Player**

### Select Media Items

**Media Tab:**
- Click in the "Media Items" field
- Search for and select your media records
- Add multiple items for a **playlist** (2+ items)

### Configure Player Options

**Settings Tab:**

| Option | Default | Description |
|--------|---------|-------------|
| **Autoplay** | Off | Start playing automatically |
| **Loop** | Off | Loop when finished |
| **Muted** | Off | Start muted |
| **Controls** | On | Show player controls |
| **Captions Default** | Off | Show captions by default |
| **Keyboard** | On | Enable keyboard shortcuts |
| **Auto Advance** | On | Auto-play next in playlist |

**Playback Settings:**
- **Volume**: Default volume (0.0 - 1.0)
- **Playback Speed**: Default speed (0.25 - 2.0)
- **Language**: Force specific UI language

**Transcript (per media item):**
- Enable transcript per media record: **Media record → Captions tab → “Enable Transcript”**
- The transcript panel is shown if **at least one** selected item has transcript enabled.

---

## Playlists

### Automatic Playlist

Select **2 or more media items** to automatically create a playlist:

- Thumbnail list appears alongside player
- Click any item to play it
- Auto-advance plays next track
- Loop option cycles through all tracks

### Best Practices

- Use consistent poster images (same dimensions)
- Add titles to all items
- Consider grouping related content
- Order items logically (drag to reorder)

---

## Player Controls

### Visual Controls

| Control | Function |
|---------|----------|
| Play/Pause | Start or pause playback |
| Volume | Adjust volume + mute |
| Progress bar | Seek to position |
| Skip previous/next | Previous/next in playlist |
| CC | Toggle captions |
| Settings | Quality, speed, captions |
| PiP | Picture-in-Picture |
| Fullscreen | Enter fullscreen |

### Keyboard Shortcuts

| Key | Action |
|-----|--------|
| **Space** or **K** | Play/Pause |
| **M** | Mute/Unmute |
| **F** | Fullscreen |
| **C** | Toggle captions |
| **←** | Seek back 10s |
| **→** | Seek forward 10s |
| **↑** | Volume up 10% |
| **↓** | Volume down 10% |
| **Home** | Go to start |
| **End** | Go to end |

---

## Privacy Layer (GDPR)

For YouTube, Vimeo, and SoundCloud:

### How It Works

1. **Before consent:** Only poster image + play button shown
2. **Privacy notice:** Explains data will be sent to external service
3. **User clicks play:** Video loads and plays immediately
4. **No cookies** until user explicitly consents

### Configure Privacy Layer Settings

**List Module → Privacy Layer Settings**

Customize the privacy layer content for all external services:

1. Create a new Privacy Layer Settings record
2. Configure settings for each service (YouTube, Vimeo, SoundCloud):
   - **Headline** (optional) - Display above privacy text
   - **Intro Text** - Text before privacy policy link
   - **Outro Text** - Text after privacy policy link
   - **Policy Link** - URL to privacy policy page
   - **Link Text** - Text for the privacy policy link
   - **Button Label** (optional) - Accessible label for play button
3. For multilingual sites: Create translated versions of the record

Settings apply to both single items and playlists. Empty fields automatically use default translations.

### What Users See

```
┌─────────────────────────────────┐
│                                 │
│      [Poster Image]             │
│                                 │
│         Play                    │
│                                 │
│  [Optional Headline]            │
│  Privacy Notice: Clicking play  │
│  will load content from YouTube │
│  and send data to Google.       │
│  [Privacy Policy Link] applies. │
└─────────────────────────────────┘
```

---

## Tips & Best Practices

### Video Quality
- **Resolution:** 1080p or 720p for web
- **Bitrate:** 5-8 Mbps for HD
- **Codec:** H.264 for MP4
- **Always** provide a poster image

### Audio Quality
- **Bitrate:** 128-320 kbps MP3
- **Sample rate:** 44.1 or 48 kHz

### Accessibility
- Always add captions for videos with speech
- Provide audio descriptions for visual content
- Use meaningful titles
- Add alt text to poster images

### Performance
- Compress videos before upload
- Use appropriate resolution (not always 4K)
- Consider adding HLS or DASH streaming sources for long content
- External services (YouTube/Vimeo) reduce server load

### Mobile
- Test on mobile devices
- The player is responsive by default and adapts to screen/container size
- Touch controls work automatically

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Video not showing | Check media record is not hidden |
| Playlist not working | Need 2+ items; check browser console |
| Captions not loading | Validate VTT syntax; check CORS headers |
| YouTube not loading | Check URL format; clear caches |
| Privacy layer stuck | Clear TYPO3 caches; check JS loading |
| Privacy settings not showing | Verify Privacy Layer Settings record exists and is not hidden |
| No sound | Check muted option; volume setting |

### VTT Validation

Ensure your VTT files:
- Start with `WEBVTT` on first line
- Have blank line after `WEBVTT`
- Use format: `HH:MM:SS.mmm --> HH:MM:SS.mmm`
- Have text on next line(s)

---

## Quick Reference

| Task | Steps |
|------|-------|
| Add video | List → New VidPly Media → Video → Upload / add streaming URL |
| Add audio | List → New VidPly Media → Audio → Upload / add streaming URL |
| Add YouTube | List → New VidPly Media → YouTube → Paste URL |
| Add captions | Edit media → Captions tab → Add VTT or SRT (SRT auto-converts on save) |
| Create playlist | VidPly Player → Select 2+ items |
| Configure privacy layer | List → Privacy Layer Settings → Create record |
| Enable privacy | Automatic for YouTube/Vimeo/SoundCloud |

---

**Need help?** Contact your site administrator or check the [technical documentation](../README.md) (repository root).

