# HLS & DASH Streaming Implementation

**HLS and DASH streaming are fully working in all modern browsers!**

## Browser Support

### HLS

| Browser | Implementation | Status |
|---|---|---|
| Chrome / Edge | hls.js (loaded from CDN on demand) | Working |
| Firefox | hls.js | Working |
| Desktop macOS Safari | hls.js (for parity with Chrome/Firefox: full quality menu, advanced caption styling) | Working |
| iOS / iPadOS Safari | Native HLS (`<video>` MSE is unavailable on iOS) — VidPly bridges native `TextTrack` API into the captions / transcript / quality UI | Working |

### DASH

| Browser | Implementation | Status |
|---|---|---|
| Chrome / Edge | dash.js | Working |
| Firefox | dash.js | Working |
| Safari | dash.js | Working |

## Technical Setup

### HLS

#### 1. hls.js Library

Loaded from CDN on demand by `mpc-vidply` (only when an `.m3u8` source is detected by the `VidPlyProcessor`):

```typoscript
page.includeJSFooter {
    hlsjs = https://cdn.jsdelivr.net/npm/hls.js@latest/dist/hls.min.js
    hlsjs.external = 1
}
```

#### 2. VidPly HLS Renderer

**File:** `libs/vidply/src/renderers/HLSRenderer.ts`

The renderer self-decides whether to use `hls.js` or the browser's native HLS support:

- On **iOS / iPadOS** (`/iPad|iPhone|iPod/` UA, or iPad in desktop mode: `MacIntel` + `maxTouchPoints > 1`) MSE is unavailable, so the renderer uses native `<video>` HLS playback through `HTML5Renderer`.
- On **everything else** (Chrome, Firefox, Edge, **and desktop macOS Safari**) it uses `hls.js` for full feature parity — quality menu, advanced caption styling and a consistent UX across browsers.

#### Native HLS bridge for iOS / iPadOS

When the native path is taken, the renderer attaches listeners to `HTMLMediaElement.textTracks` so that VidPly's caption menu, interactive transcript and quality menu still work:

```typescript
this.media.textTracks.addEventListener('addtrack', checkTracks);
this.media.textTracks.addEventListener('removetrack', checkTracks);
this.media.addEventListener('loadedmetadata', checkTracks);
```

Each `addtrack` burst (one per subtitle rendition in the manifest) is debounced (~150 ms) before VidPly recalculates the visible captions UI, so the menu is built in a single render pass even for multi-language streams.

#### Live transcript / caption updates

The renderer listens for `Hls.Events.SUBTITLE_FRAG_PROCESSED` and re-emits a generic `textcuesupdate` event so the interactive transcript and live captions stay in sync as new subtitle fragments are loaded for long / live streams. The same event is emitted by the native iOS path on `cuechange`.

### DASH

#### 1. dash.js Library

Loaded from CDN on demand by `mpc-vidply` (only when an `.mpd` source is detected):

```typoscript
page.includeJSFooter {
    dashjs = https://cdn.jsdelivr.net/npm/dashjs@latest/dist/dash.all.min.js
    dashjs.external = 1
}
```

#### 2. VidPly DASH Renderer

**File:** `libs/vidply/src/renderers/DASHRenderer.ts`

Features:
- Adaptive bitrate streaming with automatic quality switching
- TTML/stpp subtitles (rendered natively by dash.js)
- WebVTT subtitles (handled by VidPly's caption system + interactive transcript)
- Quality selection UI integration
- Robust teardown (`dash.reset()` then `dash.destroy()`) to avoid `SourceBuffer` errors on stream switching
- Speed control hidden by default for DASH streams (`hideSpeedForDash: true`)

### Content Security Policy

**File:** `Configuration/ContentSecurityPolicies.php`

Required CSP directives (shared by HLS and DASH):

```php
'media-src'       => ['blob:', 'data:', 'https:'],
'worker-src'      => ['blob:'],
'connect-src'     => ['blob:', 'data:', 'https:'],
'script-src-elem' => ['https://cdn.jsdelivr.net'],
```

Why these are needed:

- `media-src 'blob:'` — `hls.js` / `dash.js` set a `blob:` URL on `<video>.src` while playing.
- `media-src 'data:'` — some HLS variants embed init segments / WebVTT inline as `data:` URIs.
- `worker-src 'blob:'` — `hls.js` / `dash.js` spawn workers from `blob:` URLs for demuxing.
- `connect-src 'https:'` — fetching segments and manifests from arbitrary CDNs.
- `script-src-elem 'https://cdn.jsdelivr.net'` — loading `hls.min.js` / `dash.all.min.js` from jsdelivr.

## Usage

### Backend

HLS and DASH are not standalone media types. Instead, streaming sources are added as files within the **Video** or **Audio** media type.

**Adding HLS/DASH to a Video record:**
1. Create VidPly media record with type **Video**
2. Add a `.m3u8` (HLS) or `.mpd` (DASH) file as a media source
3. Optionally add progressive fallbacks (MP4, WebM) — the player will prefer DASH → HLS → progressive
4. Optionally add local VTT files to override embedded captions/subtitles
5. Add poster image (allowed formats: `png`, `jpg`, `jpeg`, `webp`, `svg`)
6. Save

**Adding HLS/DASH to an Audio record:**
1. Create VidPly media record with type **Audio**
2. Add a `.m3u8` (HLS) or `.mpd` (DASH) file as a media source
3. Optionally add progressive fallbacks (MP3, OGG)
4. Save

### Frontend

- Auto-detects stream type by MIME type or URL extension (`.m3u8` for HLS, `.mpd` for DASH)
- Source priority: DASH → HLS → progressive fallback
- iOS / iPadOS uses native HLS but still surfaces captions, transcript and quality through VidPly's UI; all other browsers use `hls.js`. All browsers use `dash.js` for DASH.
- Embedded captions/subtitles from HLS/DASH manifests are used by default; local VTT files act as optional overrides
- Quality switching available (if stream has multiple levels)
- A centered buffering spinner is shown automatically while the stream is buffering

## Testing

```bash
ddev typo3 cache:flush
```

**HLS test URLs:**
- Apple Demo: `https://devstreaming-cdn.apple.com/videos/streaming/examples/img_bipbop_adv_example_fmp4/master.m3u8`
- Akamai Demo: `https://cph-p2p-msl.akamaized.net/hls/live/2000341/test/master.m3u8`

**DASH test URLs:**
- DASH-IF: `https://dash.akamaized.net/akamai/bbb_30fps/bbb_30fps.mpd`
- Unified Streaming: `https://demo.unified-streaming.com/k8s/features/stable/video/tears-of-steel/tears-of-steel.ism/.mpd`

## Troubleshooting

**Stream not playing:**
- Check URL is publicly accessible
- Verify CORS headers on stream server (incl. `Access-Control-Allow-Origin` for `Range` requests)
- Check CSP configuration (incl. `blob:`, `data:`, `worker-src 'blob:'`)
- Look for JavaScript console errors

**No quality button:**
- Verify stream has multiple quality levels
- Check `hls.min.js` / `dash.all.min.js` loads correctly (Network tab)
- On iOS: quality renditions only show up if the manifest exposes them through native `TextTrack`s — VidPly auto-picks them up via `addtrack`

**Captions / transcript not showing on iOS HLS:**
- Verify the manifest contains `#EXT-X-MEDIA:TYPE=SUBTITLES` renditions
- Captions appear shortly after `loadedmetadata` (debounced ~150 ms) — give the player a moment after pressing play

**CSP errors:**
- Check `ContentSecurityPolicies.php` exists and is registered
- Clear all caches
- Verify `blob:` **and** `data:` are allowed for both `media-src` and `connect-src`

## Performance

- **Adaptive bitrate** — Automatically adjusts quality (HLS and DASH)
- **Buffer optimization** — Smooth playback
- **CDN delivery** — Fast library loading via jsdelivr
- **Minimal overhead** — `hls.js` / `dash.js` only load when an `.m3u8` / `.mpd` source is actually used
- **Conditional loading** — `hls.js` and `dash.js` are loaded independently based on detected source formats by the `VidPlyProcessor`
- **Native HLS on iOS** — Avoids the cost of running `hls.js` on devices where it cannot run anyway, while still keeping the full VidPly UI through the native `TextTrack` bridge
