/**
 * VidPlay Privacy Layer
 * Handles lazy-loading of external service iframes (YouTube, Vimeo, SoundCloud)
 * for GDPR/privacy compliance.
 *
 * Only loads external content after the user consents (click).
 *
 * Also provides a shared consent state for playlist integration.
 */

(function () {
    'use strict';

    // Constants
    const ASPECT_RATIO_16_9 = '56.25%';
    const SAFE_ID_PATTERN = /^[a-zA-Z0-9_-]+$/;
    const YOUTUBE_ID_PATTERN = /^[a-zA-Z0-9_-]{1,20}$/;
    const KNOWN_SERVICES = new Set(['youtube', 'vimeo', 'soundcloud']);
    const initializedLayers = new WeakSet();

    const URL_PATTERNS = {
        youtube: [
            /(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\?\/]+)/,
            /youtube\.com\/embed\/([^&\?\/]+)/,
            /youtube\.com\/v\/([^&\?\/]+)/
        ],
        vimeo: /vimeo\.com\/(?:video\/)?(\d+)/
    };

    // Sandbox tokens retain only the capabilities each embed actually needs:
    // - allow-scripts + allow-same-origin: the provider's own JS runtime
    // - allow-popups + allow-popups-to-escape-sandbox: "watch on YouTube" / "open in Vimeo" links
    // - allow-presentation: picture-in-picture / cast where supported
    // We intentionally omit allow-top-navigation so a malicious embed cannot redirect the host page.
    const IFRAME_CONFIGS = {
        youtube: {
            url: (id) => `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&modestbranding=1`,
            allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture',
            sandbox: 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation'
        },
        vimeo: {
            url: (id) => `https://player.vimeo.com/video/${id}?autoplay=1&title=0&byline=0&portrait=0`,
            allow: 'autoplay; fullscreen; picture-in-picture',
            sandbox: 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation'
        },
        soundcloud: {
            url: (url) => `https://w.soundcloud.com/player/?url=${url}&auto_play=true&hide_related=true&show_comments=false&show_user=true&show_reposts=false&show_teaser=false&visual=true`,
            allow: 'autoplay',
            sandbox: 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox'
        }
    };

    // Shared consent state - accessible globally for playlist integration
    window.VidPlyPrivacyConsent = window.VidPlyPrivacyConsent || {
        _consent: new Set(),

        hasConsent(service) {
            return KNOWN_SERVICES.has(service) && this._consent.has(service);
        },

        setConsent(service) {
            if (!KNOWN_SERVICES.has(service)) return;
            this._consent.add(service);
            document.dispatchEvent(new CustomEvent('vidply:privacy:consent', {
                detail: {service}
            }));
        }
    };

    /**
     * Extract video/track ID from URL
     */
    function extractMediaId(url, service) {
        if (service === 'youtube') {
            for (const pattern of URL_PATTERNS.youtube) {
                const match = url.match(pattern);
                if (match?.[1]) return match[1];
            }
            return null;
        }

        if (service === 'vimeo') {
            const match = url.match(URL_PATTERNS.vimeo);
            return match?.[1] || null;
        }

        if (service === 'soundcloud') {
            return encodeURIComponent(url);
        }

        return null;
    }

    /**
     * Create iframe element for a service using DOM APIs (no innerHTML)
     */
    function createIframeElement(service, mediaId, containerId) {
        const config = IFRAME_CONFIGS[service];
        if (!config) return null;

        if (containerId && !SAFE_ID_PATTERN.test(containerId)) return null;
        if (service === 'youtube' && !YOUTUBE_ID_PATTERN.test(mediaId)) return null;
        if (service === 'vimeo' && !/^\d+$/.test(mediaId)) return null;

        const iframe = document.createElement('iframe');
        if (containerId) iframe.id = containerId;
        iframe.src = config.url(mediaId);
        iframe.setAttribute('allow', config.allow);
        if (config.sandbox) {
            iframe.setAttribute('sandbox', config.sandbox);
        }
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        iframe.setAttribute('loading', 'lazy');
        iframe.setAttribute('allowfullscreen', '');
        iframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%';

        if (service === 'soundcloud') {
            iframe.setAttribute('scrolling', 'no');
            iframe.setAttribute('frameborder', 'no');
        } else {
            iframe.width = '100%';
            iframe.height = '100%';
            iframe.setAttribute('frameborder', '0');
        }

        return iframe;
    }

    /**
     * Apply aspect ratio container styles
     */
    function applyAspectRatioStyles(element) {
        Object.assign(element.style, {
            position: 'relative',
            paddingBottom: ASPECT_RATIO_16_9,
            height: '0',
            width: '100%'
        });
    }


    /**
     * Handle privacy layer click
     */
    function handlePrivacyClick(layer) {
        const service = layer.getAttribute('data-vidply-privacy');
        const url = layer.getAttribute('data-vidply-url');
        const containerId = layer.getAttribute('data-vidply-id');

        if (!url || !containerId) {
            console.error('VidPlay Privacy Layer: Missing URL or container ID');
            return;
        }

        const mediaId = extractMediaId(url, service);
        if (!mediaId) {
            console.error('VidPlay Privacy Layer: Invalid URL for service', service);
            return;
        }

        const iframe = createIframeElement(service, mediaId, containerId);
        if (!iframe) {
            console.error('VidPlay Privacy Layer: Could not create iframe for service', service);
            return;
        }

        const titleMap = {youtube: 'YouTube video player', vimeo: 'Vimeo video player', soundcloud: 'SoundCloud audio player'};
        iframe.title = titleMap[service] || 'Embedded media player';

        window.VidPlyPrivacyConsent.setConsent(service);
        applyAspectRatioStyles(layer);
        layer.replaceChildren(iframe);
        iframe.focus();
    }

    /**
     * Initialize privacy layers
     */
    function initPrivacyLayers() {
        document.querySelectorAll('[data-vidply-privacy]').forEach(layer => {
            if (initializedLayers.has(layer)) return;
            initializedLayers.add(layer);

            const button = layer.querySelector('.vidply-privacy-button');
            if (!button) return;

            let clicked = false;
            button.addEventListener('click', () => {
                if (clicked) return;
                clicked = true;
                handlePrivacyClick(layer);
            });
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrivacyLayers);
    } else {
        initPrivacyLayers();
    }

})();
