# Conditional Asset Loading

VidPly intelligently loads only the JavaScript assets needed for your specific media types, improving performance by avoiding unnecessary downloads.

## How It Works

The DataProcessor analyzes media items and sets flags indicating which assets are required (`RenderDecisionResolver::resolveAssetFlags()`):

| Flag | Loads | When |
|------|-------|------|
| `needsPrivacyLayer` | PrivacyLayer.js + privacy-layer.css | YouTube, Vimeo, or SoundCloud present |
| `needsVidPlay` | VidPly core (`vidply/vidply.esm.min.js` + code-split chunks; compiled from TypeScript, includes the buffering spinner, the optional download button and the SoundCloud renderer) | Local video or audio, every playlist, and single YouTube / Vimeo items while `mpcVidply.embedChrome` is `vidply` (default) |
| `needsPlaylist` | PlaylistInit.js | Whenever the VidPly core is loaded (player initialization, playlists) |
| `needsHLS` | hls.js **1.7.4** (`hls.min.js`) | HLS source (.m3u8) detected in media files |
| `needsDASH` | dash.js **5.2.1** (`dash.all.min.js`, modern UMD) | DASH source (.mpd) detected in media files |

The episode layouts add two more assets, loaded by `VidPly/EpisodeLayout.html`
rather than by a flag: `episode.min.css` and `EpisodeInit.min.js` (wires the
card's play button to the player). They are only emitted when the content
element's Layout field is `card` or `episodes`.

**CSS always loads** — `vidply.min.css` is lightweight and always included for consistent styling. It also contains the styles for the centered buffering spinner (`.vidply-loading` / `.vidply-buffering`) and the download button.

**YouTube and Vimeo play inside the VidPly player by default** — Single YouTube / Vimeo items load the VidPly core and `PlaylistInit.js` next to the privacy layer, so they get the VidPly control bar, captions and transcript after consent. Set the site setting `mpcVidply.embedChrome` to `provider` to keep them on the lighter privacy-layer-only path with the provider's own player (see [SettingsArchitecture.md](SettingsArchitecture.md#controls-for-youtube-and-vimeo-mpcvidplyembedchrome)).

**SoundCloud uses the privacy-layer path by default** — In `mpc-vidply` single SoundCloud media items load only `PrivacyLayer.js` + `privacy-layer.css` (not the VidPly core), because the SoundCloud Widget iframe brings its own UI. The bundled standalone `SoundCloudRenderer` is shipped inside the VidPly core bundle and is therefore only fetched for pages that already need the VidPly core for other reasons (local video / audio, HLS, DASH). See [PrivacyLayer.md → Switch SoundCloud to Renderer Mode](PrivacyLayer.md#switch-soundcloud-to-renderer-mode-advanced) for opting in.

## Examples

### Single YouTube / Vimeo Item (default, `embedChrome: vidply`)
**Loaded:**
- vidply.min.css
- privacy-layer.css
- PrivacyLayer.js (privacy overlay)
- VidPly player (`vidply/vidply.esm.min.js` + chunks; the YouTube / Vimeo renderer is loaded after consent)
- PlaylistInit.js (player initialization behind the privacy overlay)

**Not Loaded:**
- hls.js
- dash.js

### Single SoundCloud Item, or Single YouTube / Vimeo with `embedChrome: provider`
**Loaded:**
- vidply.min.css (styling, incl. spinner & download button styles)
- privacy-layer.css (privacy layer styles)
- PrivacyLayer.js (consent handling, mounts the service iframe after consent)

**Not Loaded:**
- VidPly player core (and therefore not the bundled `SoundCloudRenderer` either — see [PrivacyLayer.md](PrivacyLayer.md#switch-soundcloud-to-renderer-mode-advanced) for the renderer-mode opt-in)
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
- VidPly player (playlist UI; YouTube / Vimeo tracks play through its renderers)
- PlaylistInit.js (for playlist management)

**Not Loaded:**
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
- Total: ~613KB transferred (gzip, all extension JavaScript combined)

**After optimization:**
- Single SoundCloud, or single YouTube / Vimeo with `embedChrome: provider`: ~7KB (PrivacyLayer.js + privacy-layer.css)
- Single YouTube / Vimeo (default `embedChrome: vidply`): ~187KB (privacy layer + VidPly core + PlaylistInit)
- Single local video: ~145KB (VidPly core + PlaylistInit) — already includes the buffering spinner, optional download button and the SoundCloud renderer
- Video with HLS source: ~330KB (VidPly + PlaylistInit + hls.js)
- Video with DASH source: ~370KB (VidPly + PlaylistInit + dash.js)
- Video with HLS + DASH sources: ~560KB (VidPly + PlaylistInit + hls.js + dash.js)

**Savings:**
- External services on the privacy-layer-only path: 97% reduction (~350KB → ~7KB)
- Local video without streaming: ~65% reduction vs. the unoptimized "load everything" baseline

> Scenario sizes above are **approximate gzip transfer** (minified sources). On-disk minified files are larger — e.g. `hls.min.js` ≈ 609 KB, `dash.all.min.js` ≈ 782 KB, all `vidply/*.js` chunks combined ≈ 542 KB.

## Implementation

### DataProcessor (VidPlyProcessor.php → RenderDecisionResolver.php)

```php
// $serviceType: 'youtube' | 'vimeo' | 'soundcloud' for a single external item, null otherwise.
// $singleEmbedInPlayer: true for a single YouTube/Vimeo item while embedChrome is 'vidply'
// (always false for SoundCloud, whose chrome is fixed to 'provider').
$isPlaylist = $trackResult['isPlaylist'];
$needsPrivacyLayer = $serviceType !== null || ($isPlaylist && $trackResult['hasExternalMedia']);
$needsVidPlay = $isPlaylist || $serviceType === null || $singleEmbedInPlayer;
$needsPlaylist = $isPlaylist || $needsVidPlay;

// HLS and DASH streams are detected by MIME type on every track and its sources
// (MediaMimeType::isHls() / MediaMimeType::isDash()).
```

### Template (Partials/VidPly/Player.html)

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

<!-- HLS.js 1.7.4 - only for HLS streams -->
<f:if condition="{needsHLS}">
    <f:asset.script identifier="vidPlyHLS" src="EXT:mpc_vidply/Resources/Public/JavaScript/hls.min.js" defer="true"/>
</f:if>

<!-- dash.js 5.2.1 (modern UMD) - only for DASH streams -->
<f:if condition="{needsDASH}">
    <f:asset.script identifier="vidPlyDASH" src="EXT:mpc_vidply/Resources/Public/JavaScript/dash.all.min.js" defer="true"/>
</f:if>

<!-- VidPly Core - local media, playlists, single YouTube/Vimeo with embedChrome: vidply -->
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

1. **Faster Page Load** - Especially for SoundCloud and for YouTube / Vimeo with `embedChrome: provider`
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
2. Create page with a single SoundCloud item
3. Reload page, check only PrivacyLayer.js loads (no VidPly core)
4. Create page with a single YouTube video
5. Reload, verify PrivacyLayer.js, the VidPly core and PlaylistInit.js load — but no request to YouTube before you click play
6. Create page with local MP4
7. Reload, verify VidPly core loads but PrivacyLayer.js doesn't

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

