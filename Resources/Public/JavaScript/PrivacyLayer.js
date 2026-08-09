/**
 * VidPlay Privacy Layer
 * Handles lazy-loading of external service iframes (YouTube, Vimeo, SoundCloud)
 * for GDPR/privacy compliance.
 *
 * Only loads external content after the user consents (click).
 *
 * Also provides a shared consent state for playlist integration.
 */

import { bootstrap, resolveScope } from './shared/bootstrap.js';
import { privacyConsent } from './shared/consent.js';
import { extractMediaId } from './shared/media-services.js';
import { sanitizeCssUrl } from './shared/url-safety.js';

(function () {
    'use strict';

    // Constants
    const ASPECT_RATIO_16_9 = '56.25%';
    const SAFE_ID_PATTERN = /^[a-zA-Z0-9_-]+$/;
    const YOUTUBE_ID_PATTERN = /^[a-zA-Z0-9_-]{1,20}$/;
    const initializedLayers = new WeakSet();

    /**
     * Apply the poster as a background image at runtime.
     *
     * Previously emitted as an inline style attribute in PrivacyLayer.html;
     * setting it via element.style here keeps the markup free of inline styles
     * so the CSP no longer needs style-src 'unsafe-inline'. The URL is already
     * sanitized server-side; we re-validate it client-side as defense in depth.
     */
    const applyPosterBackground = (layer) => {
        const safe = sanitizeCssUrl(layer.getAttribute('data-vidply-poster'));
        if (!safe) return;
        layer.style.setProperty('background-image', `url("${safe}")`);
        layer.style.setProperty('background-size', 'cover');
        layer.style.setProperty('background-position', 'center');
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

        privacyConsent.setConsent(service);
        applyAspectRatioStyles(layer);
        layer.replaceChildren(iframe);
        iframe.focus();
    }

    /**
     * Initialize privacy layers within a DOM subtree.
     *
     * @param {ParentNode} [root=document]
     */
    function initPrivacyLayers(root = document) {
        const scope = resolveScope(root);

        scope.querySelectorAll('[data-vidply-privacy]').forEach(layer => {
            if (initializedLayers.has(layer)) return;
            initializedLayers.add(layer);

            applyPosterBackground(layer);

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

    window.VidPlyPrivacy = window.VidPlyPrivacy || {};
    window.VidPlyPrivacy.scanLayers = initPrivacyLayers;

    bootstrap(initPrivacyLayers);

    // Vue/Swiper containers that were already hydrated before this script ran
    // never fire the dynamic-content event, so catch up with them once.
    document.querySelectorAll('[data-container="vue"].swiper-vue-ready').forEach((root) => {
        initPrivacyLayers(root);
    });

})();
