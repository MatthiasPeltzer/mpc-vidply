# VidPlay Privacy Layer

GDPR-compliant loading of external media services (YouTube, Vimeo, SoundCloud).

## Overview

External content only loads **after explicit user consent**, preventing tracking before interaction.

## Supported Services

| Service | Type | Domain | Features |
|---------|------|--------|----------|
| YouTube | Video | `youtube-nocookie.com` | Privacy-enhanced |
| Vimeo | Video | `player.vimeo.com` | Standard player |
| SoundCloud | Audio/Sets | `w.soundcloud.com` | Visual waveform |

## How It Works

### Before Click
- Play button overlay with poster image
- Privacy notice with policy link
- **Zero external requests**
- No tracking scripts loaded

### After Click
- Privacy layer replaced with iframe
- Service player loads
- **Auto-play enabled** (immediate playback)
- Service's privacy policy applies

## User Experience

**YouTube/Vimeo:**
> "To activate the video, you must click on the button. After activating the button, **Google's privacy policy** applies."

**SoundCloud:**
> "To activate the widget, you must click on the button. After activating the button, **SoundCloud's privacy policy** applies."

**German translations included** - Automatically switches based on TYPO3 site language.

## Setup in Backend

### YouTube/Vimeo
1. Create VidPlay media record
2. Select **YouTube** or **Vimeo** media type
3. Add video URL or use TYPO3 online media helper
4. Add poster image (recommended)
5. Save

### SoundCloud
1. Create VidPlay media record
2. Select **SoundCloud** media type
3. Paste track or set URL:
   - Track: `https://soundcloud.com/user/track`
   - Set: `https://soundcloud.com/user/sets/playlist`
4. Add poster image (recommended)
5. Save

## Technical Implementation

### Files
- `Partials/VidPly/PrivacyLayer.html` - Consent layer template
- `JavaScript/PrivacyLayer.js` - Lazy iframe loading
- Language files with translations

### JavaScript
```javascript
// Lazy iframe creation (only after click)
button.addEventListener('click', function() {
    const service = layer.getAttribute('data-vidply-privacy');
    const url = layer.getAttribute('data-vidply-url');
    
    // Create and insert iframe with autoplay
    layer.innerHTML = createIframe(service, url);
});
```

### Template Logic
```html
<f:if condition="{vidply.serviceType}">
    <f:then>
        <!-- Privacy layer (YouTube, Vimeo, SoundCloud) -->
        <f:render partial="VidPly/PrivacyLayer" />
    </f:then>
    <f:else>
        <!-- VidPlay player (local, HLS) -->
        <f:render section="VideoPlayer" />
    </f:else>
</f:if>
```

## Features

### Privacy
- No external requests before consent
- No cookies until user interaction
- GDPR compliant
- Clear privacy notices

### UX
- Single click activation
- Auto-play (no double-click)
- Visual play button (VidPlay style)
- 16:9 aspect ratio maintained

### Technical
- Lazy iframe creation
- Service-specific embed URLs
- Multilingual support (EN/DE)
- Minimal overhead (~5KB JS)

## iframe Configuration

### YouTube
```
https://www.youtube-nocookie.com/embed/{ID}
?autoplay=1&rel=0&modestbranding=1
```
- Privacy-enhanced domain
- No related video recommendations
- Clean branding

### Vimeo
```
https://player.vimeo.com/video/{ID}
?autoplay=1&title=0&byline=0&portrait=0
```
- Clean UI (no metadata)
- Auto-play enabled

### SoundCloud
```
https://w.soundcloud.com/player/
?url={URL}&auto_play=true&visual=true&hide_related=true
```
- Visual waveform (16:9)
- Clean UI
- Works with tracks and sets/playlists

## Translations

### English
- `privacy.activate_intro` - "To activate the video..."
- `privacy.activate_intro_widget` - "To activate the widget..."
- `privacy.youtube.policy_link` - "Google's privacy policy"
- `privacy.vimeo.policy_link` - "Vimeo's privacy policy"
- `privacy.soundcloud.policy_link` - "SoundCloud's privacy policy"

### German
- `privacy.activate_intro` - "Um das Video zu aktivieren..."
- `privacy.activate_intro_widget` - "Um das Widget zu aktivieren..."
- `privacy.youtube.policy_link` - "die Datenschutzerklärung von Google"
- `privacy.vimeo.policy_link` - "die Datenschutzerklärung von Vimeo"
- `privacy.soundcloud.policy_link` - "die Datenschutzerklärung von SoundCloud"

## Customization

### Change Privacy Text

Edit language files:
```xml
<trans-unit id="privacy.activate_intro">
    <source>Your custom text</source>
    <target>Ihr eigener Text</target>
</trans-unit>
```

### Custom Styling

Override CSS:
```css
.vidply-privacy-layer {
    /* Your styles */
}

.vidply-privacy-button:hover svg {
    transform: scale(1.15);
}
```

### Add More Services

1. Add service to TCA media types
2. Add case in `PrivacyLayer.js` `createXyzIframe()`
3. Add translations
4. Update DataProcessor to handle new type

## Browser Support

- Chrome/Edge 90+
- Firefox 88+
- Safari 14+
- Mobile browsers

## Testing

```bash
ddev typo3 cache:flush
```

1. Create test media (YouTube, Vimeo, SoundCloud)
2. Check **no** external requests on page load
3. Click play button
4. Verify iframe loads and plays immediately
5. Test multilingual sites (EN/DE)

## Performance

- **Zero impact** until user interaction
- **~5KB** JavaScript (PrivacyLayer.js)
- **No VidPlay initialization** for external services
- **Cached after first load**
