# HLS & DASH Streaming Implementation

**HLS and DASH streaming are fully working in all modern browsers!**

## Browser Support

### HLS

| Browser | Implementation | Status |
|---------|---------------|--------|
| Chrome/Edge | hls.js | Working |
| Firefox | hls.js | Working |
| Safari/iOS | Native | Working |

### DASH

| Browser | Implementation | Status |
|---------|---------------|--------|
| Chrome/Edge | dash.js | Working |
| Firefox | dash.js | Working |
| Safari | dash.js | Working |

## Technical Setup

### HLS

#### 1. hls.js Library

**Loaded from CDN:**
```typoscript
page.includeJSFooter {
    hlsjs = https://cdn.jsdelivr.net/npm/hls.js@latest/dist/hls.min.js
    hlsjs.external = 1
}
```

#### 2. VidPly HLS Renderer

**File:** `libs/vidply/src/renderers/HLSRenderer.js`

**Key Fix:**
```javascript
canPlayNatively() {
    // Force hls.js on Chrome/Firefox (they return 'maybe' but don't support HLS natively)
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    
    if (!isSafari && !isIOS) {
        return false; // Use hls.js
    }
    
    return video.canPlayType('application/vnd.apple.mpegurl') !== '';
}
```

### DASH

#### 1. dash.js Library

**Loaded from CDN:**
```typoscript
page.includeJSFooter {
    dashjs = https://cdn.jsdelivr.net/npm/dashjs@latest/dist/dash.all.min.js
    dashjs.external = 1
}
```

#### 2. VidPly DASH Renderer

**File:** `libs/vidply/src/renderers/DASHRenderer.js`

Features:
- Adaptive bitrate streaming with automatic quality switching
- TTML and WebVTT subtitle support
- Quality selection UI integration

### Content Security Policy

**File:** `Configuration/ContentSecurityPolicies.php`

Required CSP directives (shared by HLS and DASH):
```php
'media-src' => ['blob:', 'data:', 'https:'],
'worker-src' => ['blob:'],
'connect-src' => ['blob:', 'data:', 'https:'],
'script-src-elem' => ['https://cdn.jsdelivr.net'],
```

## Usage

### Backend

**HLS:**
1. Create VidPly media record
2. Select **HLS Stream** media type
3. Enter stream URL: `https://example.com/stream.m3u8`
4. Add poster image
5. Save

**DASH:**
1. Create VidPly media record
2. Select **DASH Stream** media type
3. Enter manifest URL: `https://example.com/stream/manifest.mpd`
4. Add poster image
5. Save

### Frontend

- Auto-detects stream type by URL extension (`.m3u8` for HLS, `.mpd` for DASH)
- Safari uses native HLS; all browsers use dash.js for DASH
- Quality switching available (if stream has multiple levels)

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
- Verify CORS headers on stream server
- Check CSP configuration
- Look for JavaScript console errors

**No quality button:**
- Verify stream has multiple quality levels
- Check hls.js / dash.js loads correctly
- For HLS: ensure browser is not Safari (native HLS)

**CSP errors:**
- Check `ContentSecurityPolicies.php` exists
- Clear all caches
- Verify blob: and data: are allowed

## Performance

- **Adaptive bitrate** - Automatically adjusts quality (HLS and DASH)
- **Buffer optimization** - Smooth playback
- **CDN delivery** - Fast library loading via jsdelivr
- **Minimal overhead** - Libraries only load when needed
- **Conditional loading** - hls.js and dash.js are loaded independently based on media types
