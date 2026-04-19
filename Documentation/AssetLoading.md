# Conditional Asset Loading

VidPly intelligently loads only the JavaScript assets needed for your specific media types, improving performance by avoiding unnecessary downloads.

## How It Works

The DataProcessor analyzes media items and sets flags indicating which assets are required:

| Flag | Loads | When |
|------|-------|------|
| `needsPrivacyLayer` | PrivacyLayer.js + privacy-layer.css | YouTube, Vimeo, or SoundCloud present |
| `needsVidPlay` | VidPly core (`vidply/vidply.esm.min.js` + code-split chunks; compiled from TypeScript, includes the buffering spinner, the optional download button and the SoundCloud renderer) | Video or audio media (not external services) |
| `needsPlaylist` | PlaylistInit.js | 2+ media items OR native player |
| `needsHLS` | hls.js | HLS source (.m3u8) detected in media files |
| `needsDASH` | dash.js | DASH source (.mpd) detected in media files |

**CSS always loads** — `vidply.min.css` is lightweight and always included for consistent styling. It also contains the styles for the centered buffering spinner (`.vidply-loading` / `.vidply-buffering`) and the download button.

**SoundCloud uses the privacy-layer path by default** — In `mpc-vidply` SoundCloud media items load only `PrivacyLayer.js` + `privacy-layer.css` (not the VidPly core), because the SoundCloud Widget iframe brings its own UI. The bundled standalone `SoundCloudRenderer` is shipped inside the VidPly core bundle and is therefore only fetched for pages that already need the VidPly core for other reasons (local video / audio, HLS, DASH). See **PrivacyLayer.md → "Switch SoundCloud to Renderer Mode"** for opting in.

## Examples

### Single YouTube / Vimeo / SoundCloud Item
**Loaded:**
- vidply.min.css (styling, incl. spinner & download button styles)
- privacy-layer.css (privacy layer styles)
- PrivacyLayer.js (consent handling, mounts the service iframe after consent)

**Not Loaded:**
- VidPly player core (and therefore not the bundled `SoundCloudRenderer` either — see PrivacyLayer.md for the renderer-mode opt-in)
- PlaylistInit.js
- hls.js
- dash.js

### Single Local MP4 Video
**Loaded:**
- vidply.min.css
- VidPly player (`vidply/vidply.esm.min.js` + chunks)
- PlaylistInit.js (player initialization)

**Not Loaded:**
- PrivacyLayer.js
- hls.js

### Video with HLS Source
**Loaded:**
- vidply.min.css
- VidPly player
- PlaylistInit.js
- hls.js (adaptive streaming)

**Not Loaded:**
- PrivacyLayer.js
- dash.js

### Video with DASH Source
**Loaded:**
- vidply.min.css
- VidPly player
- PlaylistInit.js
- dash.js (MPEG-DASH streaming)

**Not Loaded:**
- PrivacyLayer.js
- hls.js

### Playlist with 3 MP4 Videos
**Loaded:**
- vidply.min.css
- VidPly player
- PlaylistInit.js (playlist + player)

**Not Loaded:**
- PrivacyLayer.js
- hls.js

### Mixed Playlist (YouTube + Vimeo + SoundCloud)
**Loaded:**
- vidply.min.css
- privacy-layer.css
- PrivacyLayer.js
- PlaylistInit.js (for playlist management)

**Not Loaded:**
- VidPly player (external services use native iframes via the privacy layer)
- hls.js
- dash.js

### Mixed Playlist (Local MP4 + YouTube)
**Loaded:**
- vidply.min.css
- privacy-layer.css
- PrivacyLayer.js (for the YouTube item)
- VidPly core (for the MP4 item — also brings the buffering spinner, download button and SoundCloud renderer along, even though they aren't all used)
- PlaylistInit.js

## Performance Impact

**Before optimization:**
- All JavaScript files loaded on every page with VidPly
- Total: ~530KB (including hls.js from CDN)

**After optimization:**
- Single YouTube / Vimeo / SoundCloud: ~7KB (PrivacyLayer.js + privacy-layer.css)
- Single local video: ~180KB (VidPly core + PlaylistInit) — already includes the buffering spinner, optional download button and the SoundCloud renderer
- Video with HLS source: ~240KB (VidPly + PlaylistInit + hls.js)
- Video with DASH source: ~380KB (VidPly + PlaylistInit + dash.js)
- Video with HLS + DASH sources: ~440KB (VidPly + PlaylistInit + hls.js + dash.js)

**Savings:**
- External services: 97% reduction (~350KB → ~7KB)
- Local video without streaming: ~65% reduction vs. the unoptimized "load everything" baseline

> Sizes are minified, **uncompressed**. Real-world transfer is typically 30–40% of these numbers after gzip / brotli.

## Implementation

### DataProcessor (VidPlyProcessor.php)

```php
// Analyze media types
$needsPrivacyLayer = false;
$needsVidPlay = false;
$needsPlaylist = false;
$needsHLS = false;
$needsDASH = false;

// Check for external services
if (in_array($firstTrackType, ['youtube', 'vimeo', 'soundcloud'])) {
    // External services use the privacy-layer iframe path.
    // SoundCloud could alternatively be routed through the bundled
    // `SoundCloudRenderer` (set $needsVidPlay = true) — see PrivacyLayer.md.
    $needsPrivacyLayer = true;
} else {
    $needsVidPlay = true; // Native VidPly player for local video/audio (incl. HLS/DASH sources)
    $needsPlaylist = true;
}

// Check for HLS and DASH streams (detected by MIME type)
foreach ($tracks as $track) {
    if (in_array($track['type'], ['application/x-mpegurl', 'application/vnd.apple.mpegurl'])) {
        $needsHLS = true;
    }
    if ($track['type'] === 'application/dash+xml') {
        $needsDASH = true;
    }
}
```

### Template (VidPly.html)

```html
<f:render partial="VidPly/Assets" arguments="{
    needsPrivacyLayer: vidply.needsPrivacyLayer,
    needsVidPlay: vidply.needsVidPlay,
    needsPlaylist: vidply.needsPlaylist,
    needsHLS: vidply.needsHLS,
    needsDASH: vidply.needsDASH
}" />
```

### Assets Partial (VidPly/Assets.html)

```html
<!-- Privacy Layer JS - only for external services -->
<f:if condition="{needsPrivacyLayer}">
    <f:asset.script identifier="vidPlyPrivacy" src="..." type="module"/>
</f:if>

<!-- HLS.js - only for HLS streams -->
<f:if condition="{needsHLS}">
    <f:asset.script identifier="vidPlyHLS" src="https://cdn.jsdelivr.net/..." />
</f:if>

<!-- dash.js - only for DASH streams -->
<f:if condition="{needsDASH}">
    <f:asset.script identifier="vidPlyDASH" src="https://cdn.jsdelivr.net/..." />
</f:if>

<!-- VidPly Core - only for native player -->
<f:if condition="{needsVidPlay}">
    <f:asset.script identifier="vidPlyWrapper" src="..." />
    <f:asset.script identifier="vidPly" src="..." type="module"/>
</f:if>

<!-- Playlist Init - for playlists or player initialization -->
<f:if condition="{needsPlaylist}">
    <f:asset.script identifier="playlistInit" src="..." type="module"/>
</f:if>
```

## Benefits

1. **Faster Page Load** - Especially for external service videos (YouTube, Vimeo)
2. **Reduced Bandwidth** - Users only download what they need
3. **Better Caching** - Unused scripts don't pollute browser cache
4. **Automatic** - No configuration needed, works based on media types
5. **Smart Detection** - Per-page optimization based on actual content

## Browser Compatibility

All major browsers support conditional asset loading through TYPO3's Asset API:
- Chrome/Edge 90+
- Firefox 88+
- Safari 14+
- Mobile browsers

## Testing

To verify conditional loading:

1. Open browser DevTools → Network tab
2. Create page with single YouTube video
3. Reload page, check only PrivacyLayer.js loads
4. Create page with local MP4
5. Reload, verify VidPly core loads but PrivacyLayer.js doesn't

## Troubleshooting

**Assets not loading:**
- Clear all TYPO3 caches
- Check browser console for errors
- Verify DataProcessor is registered

**Wrong assets loading:**
- Check media type detection in DataProcessor
- Verify flags are passed to template
- Clear template cache

**External services broken:**
- Ensure PrivacyLayer.js loads for YouTube/Vimeo/SoundCloud
- Check browser console for JavaScript errors

## Future Enhancements

Potential future optimizations:
- Lazy load CSS (currently always loads)
- Split VidPly core into smaller modules (the SoundCloud renderer, download button and buffering spinner are already lazy-loadable as separate chunks via TypeScript dynamic `import()`s — exposing per-feature flags to the DataProcessor would let us drop them when not needed)
- Preload critical assets based on above-the-fold content
- Service worker for offline caching

