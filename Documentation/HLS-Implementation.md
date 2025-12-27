# HLS Streaming Implementation

**HLS streaming is fully working in all modern browsers!**

## Browser Support

| Browser | Implementation | Status |
|---------|---------------|--------|
| Chrome/Edge | hls.js | Working |
| Firefox | hls.js | Working |
| Safari/iOS | Native | Working |

## Technical Setup

### 1. hls.js Library

**Loaded from CDN:**
```typoscript
page.includeJSFooter {
    hlsjs = https://cdn.jsdelivr.net/npm/hls.js@latest/dist/hls.min.js
    hlsjs.external = 1
}
```

### 2. VidPly HLS Renderer

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

### 3. Content Security Policy

**File:** `Configuration/ContentSecurityPolicies.php`

Required CSP directives:
```php
'media-src' => ['blob:', 'data:', 'https:'],
'worker-src' => ['blob:'],
'connect-src' => ['blob:', 'data:', 'https:'],
'script-src-elem' => ['https://cdn.jsdelivr.net'],
```

## Usage

### Backend

1. Create VidPly media record
2. Select **HLS Stream** media type
3. Enter stream URL: `https://example.com/stream.m3u8`
4. Add poster image
5. Save

### Frontend

- Auto-detects HLS streams
- Safari uses native HLS
- Other browsers use hls.js
- Quality switching available (if stream has multiple levels)

## Testing

```bash
ddev typo3 cache:flush
```

Test URLs:
- Apple Demo: `https://devstreaming-cdn.apple.com/videos/streaming/examples/img_bipbop_adv_example_fmp4/master.m3u8`
- Akamai Demo: `https://cph-p2p-msl.akamaized.net/hls/live/2000341/test/master.m3u8`

## Troubleshooting

**Stream not playing:**
- Check URL is publicly accessible
- Verify CORS headers on stream server
- Check CSP configuration
- Look for JavaScript console errors

**No quality button:**
- Verify stream has multiple quality levels
- Check hls.js loads correctly
- Ensure browser is not Safari (native HLS)

**CSP errors:**
- Check `ContentSecurityPolicies.php` exists
- Clear all caches
- Verify blob: and data: are allowed

## Performance

- **Adaptive bitrate** - Automatically adjusts quality
- **Buffer optimization** - Smooth playback
- **CDN delivery** - Fast hls.js loading
- **Minimal overhead** - Only loads when needed
