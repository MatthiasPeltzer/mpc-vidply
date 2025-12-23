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

**Default text (YouTube/Vimeo):**
> "To activate the video, you must click on the button. After activating the button, **Google's privacy policy** applies."

**Default text (SoundCloud):**
> "To activate the widget, you must click on the button. After activating the button, **SoundCloud's privacy policy** applies."

**Customizable** - All privacy texts, links, headlines, and button labels can be configured in the backend.

**Multilingual support** - Privacy settings can be translated per language.

**German translations included** - Automatically switches based on TYPO3 site language.

## Setup in Backend

### Configure Privacy Layer Settings

**List Module → Privacy Layer Settings**

Create a record to customize privacy layer content for YouTube, Vimeo, and SoundCloud:

| Field | Description | Required |
|-------|-------------|----------|
| **Headline** | Optional headline displayed above privacy text | No |
| **Intro Text** | Text before privacy policy link | Yes (fallback to default) |
| **Outro Text** | Text after privacy policy link | Yes (fallback to default) |
| **Policy Link** | URL to privacy policy page | Yes (fallback to default) |
| **Link Text** | Text for the privacy policy link | Yes (fallback to default) |
| **Button Label** | Accessible label for play button | No (fallback to default) |

Settings support multilingual content via TYPO3's translation system. Empty fields automatically fall back to default translations.

### Create Media Records

**YouTube/Vimeo:**
1. Create VidPlay media record
2. Select **YouTube** or **Vimeo** media type
3. Add video URL or use TYPO3 online media helper
4. Add poster image (recommended)
5. Save

**SoundCloud:**
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
- `JavaScript/PlaylistInit.js` - Playlist privacy layer handling
- `Classes/Service/PrivacySettingsService.php` - Fetches privacy settings from database
- `Configuration/TCA/tx_mpcvidply_privacy_settings.php` - Backend configuration
- `Resources/Public/Css/privacy-layer.css` - Privacy layer styles
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
- Centralized backend configuration

### UX
- Single click activation
- Auto-play (no double-click)
- Visual play button (VidPlay style)
- 16:9 aspect ratio maintained
- Customizable headlines, texts, and links

### Technical
- Lazy iframe creation
- Service-specific embed URLs
- Multilingual support (EN/DE + backend translations)
- Database-driven configuration
- Works for single items and playlists
- Minimal overhead (~5KB JS + CSS)

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

## Database Structure

### Table: `tx_mpcvidply_privacy_settings`

Site-wide privacy layer configuration stored in database:

| Column | Type | Description |
|--------|------|-------------|
| `youtube_headline` | varchar(255) | Optional headline for YouTube |
| `youtube_intro_text` | text | Intro text before link |
| `youtube_outro_text` | text | Outro text after link |
| `youtube_policy_link` | varchar(255) | Privacy policy URL |
| `youtube_link_text` | varchar(255) | Link text |
| `youtube_button_label` | varchar(255) | Button aria-label |
| `vimeo_*` | (same fields) | Vimeo settings |
| `soundcloud_*` | (same fields) | SoundCloud settings |
| `sys_language_uid` | int | Language ID (0 = default) |

## Translations

### Default Language File Translations (Fallback)

**English:**
- `privacy.activate_intro` - "To activate the video..."
- `privacy.activate_intro_widget` - "To activate the widget..."
- `privacy.youtube.policy_link` - "Google's privacy policy"
- `privacy.vimeo.policy_link` - "Vimeo's privacy policy"
- `privacy.soundcloud.policy_link` - "SoundCloud's privacy policy"

**German:**
- `privacy.activate_intro` - "Um das Video zu aktivieren..."
- `privacy.activate_intro_widget` - "Um das Widget zu aktivieren..."
- `privacy.youtube.policy_link` - "die Datenschutzerklärung von Google"
- `privacy.vimeo.policy_link` - "die Datenschutzerklärung von Vimeo"
- `privacy.soundcloud.policy_link` - "die Datenschutzerklärung von SoundCloud"

Note: Backend database settings override language file translations.

## Customization

### Change Privacy Text via Backend

**Recommended:** Use Privacy Layer Settings in the backend (List Module → Privacy Layer Settings). This allows:
- Centralized management
- Multilingual support
- No code changes required
- Easy updates by editors

### Change Privacy Text via Language Files

Edit language files for fallback defaults:
```xml
<trans-unit id="privacy.activate_intro">
    <source>Your custom text</source>
    <target>Ihr eigener Text</target>
</trans-unit>
```

Note: Backend settings take precedence over language file translations.

### Custom Styling

Privacy layer styles are in `Resources/Public/Css/privacy-layer.css`. Override in your sitepackage:

```css
.vidply-privacy-layer {
    /* Your styles */
}

.vidply-privacy-button:hover svg {
    transform: scale(1.15);
}

.vidply-privacy-text .h6 {
    /* Customize headline styling */
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
- **~2KB** CSS (privacy-layer.css, loaded conditionally)
- **No VidPlay initialization** for external services
- **Cached after first load**
- **Database queries** cached by TYPO3
