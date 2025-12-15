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
    const IFRAME_STYLE = 'position: absolute; top: 0; left: 0; width: 100%; height: 100%;';
    const IFRAME_BASE_ATTRS = 'width="100%" height="100%" frameborder="0" allowfullscreen';

    const URL_PATTERNS = {
        youtube: [
            /(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\?\/]+)/,
            /youtube\.com\/embed\/([^&\?\/]+)/,
            /youtube\.com\/v\/([^&\?\/]+)/
        ],
        vimeo: /vimeo\.com\/(?:video\/)?(\d+)/
    };

    const IFRAME_CONFIGS = {
        youtube: {
            url: (id) => `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&modestbranding=1`,
            allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture'
        },
        vimeo: {
            url: (id) => `https://player.vimeo.com/video/${id}?autoplay=1&title=0&byline=0&portrait=0`,
            allow: 'autoplay; fullscreen; picture-in-picture'
        },
        soundcloud: {
            url: (url) => `https://w.soundcloud.com/player/?url=${url}&auto_play=true&hide_related=true&show_comments=false&show_user=true&show_reposts=false&show_teaser=false&visual=true`,
            allow: 'autoplay',
            extraAttrs: 'scrolling="no" frameborder="no"'
        }
    };

    // Shared consent state - accessible globally for playlist integration
    window.VidPlyPrivacyConsent = window.VidPlyPrivacyConsent || {
        youtube: false,
        vimeo: false,
        soundcloud: false,

        hasConsent(service) {
            return !!this[service];
        },

        setConsent(service) {
            this[service] = true;
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
     * Create iframe HTML for a service
     */
    function createIframe(service, mediaId, containerId) {
        const config = IFRAME_CONFIGS[service];
        if (!config) return '';

        const baseAttrs = service === 'soundcloud' ? config.extraAttrs : IFRAME_BASE_ATTRS;

        return `
            <iframe
                id="${containerId}"
                ${baseAttrs}
                src="${config.url(mediaId)}"
                allow="${config.allow}"
                style="${IFRAME_STYLE}">
            </iframe>
        `;
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
     * Setup button event listeners
     */
    function setupButtonEffects(button) {
        button.addEventListener('mouseenter', () => button.style.transform = 'scale(1.1)');
        button.addEventListener('mouseleave', () => button.style.transform = 'scale(1)');
        button.addEventListener('focus', () => {
            button.style.outline = '3px solid rgba(255, 255, 255, 0.6)';
            button.style.outlineOffset = '4px';
        });
        button.addEventListener('blur', () => button.style.outline = 'none');
    }

    /**
     * Handle privacy layer click
     */
    function handlePrivacyClick(layer, button) {
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

        const iframeHtml = createIframe(service, mediaId, containerId);
        if (!iframeHtml) {
            console.error('VidPlay Privacy Layer: Unknown service', service);
            return;
        }

        // Set consent and replace layer with iframe
        window.VidPlyPrivacyConsent.setConsent(service);
        applyAspectRatioStyles(layer);
        layer.innerHTML = iframeHtml;
    }

    /**
     * Initialize privacy layers
     */
    function initPrivacyLayers() {
        document.querySelectorAll('[data-vidply-privacy]').forEach(layer => {
            const button = layer.querySelector('.vidply-privacy-button');
            if (!button) return;

            button.addEventListener('click', () => handlePrivacyClick(layer, button));
            setupButtonEffects(button);
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPrivacyLayers);
    } else {
        initPrivacyLayers();
    }

})();
