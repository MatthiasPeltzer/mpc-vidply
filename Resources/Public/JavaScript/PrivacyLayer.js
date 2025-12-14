/**
 * VidPlay Privacy Layer
 * Handles lazy-loading of external service iframes (YouTube, Vimeo, SoundCloud)
 * for GDPR/privacy compliance.
 * 
 * Only loads external content after user consent (click).
 * 
 * Also provides shared consent state for playlist integration.
 */

(function() {
    'use strict';

    // Shared consent state - accessible globally for playlist integration
    window.VidPlyPrivacyConsent = window.VidPlyPrivacyConsent || {
        youtube: false,
        vimeo: false,
        soundcloud: false
    };

    /**
     * Check if consent has been given for a service
     * @param {string} service - Service name (youtube, vimeo, soundcloud)
     * @returns {boolean} - Whether consent has been given
     */
    window.VidPlyPrivacyConsent.hasConsent = function(service) {
        return !!this[service];
    };

    /**
     * Set consent for a service
     * @param {string} service - Service name (youtube, vimeo, soundcloud)
     */
    window.VidPlyPrivacyConsent.setConsent = function(service) {
        this[service] = true;
        // Dispatch custom event for playlist integration
        document.dispatchEvent(new CustomEvent('vidply:privacy:consent', {
            detail: { service }
        }));
    };

    /**
     * Extract video ID from YouTube URL
     */
    function getYouTubeId(url) {
        const patterns = [
            /(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\?\/]+)/,
            /youtube\.com\/embed\/([^&\?\/]+)/,
            /youtube\.com\/v\/([^&\?\/]+)/
        ];
        
        for (const pattern of patterns) {
            const match = url.match(pattern);
            if (match && match[1]) {
                return match[1];
            }
        }
        return null;
    }

    /**
     * Extract video ID from Vimeo URL
     */
    function getVimeoId(url) {
        const pattern = /vimeo\.com\/(?:video\/)?(\d+)/;
        const match = url.match(pattern);
        return match ? match[1] : null;
    }

    /**
     * Extract track ID from SoundCloud URL
     */
    function getSoundCloudUrl(url) {
        // SoundCloud needs the full URL for embedding
        return encodeURIComponent(url);
    }

    /**
     * Create YouTube iframe
     */
    function createYouTubeIframe(videoId, containerId) {
        return `
            <iframe
                id="${containerId}"
                width="100%"
                height="100%"
                src="https://www.youtube-nocookie.com/embed/${videoId}?autoplay=1&rel=0&modestbranding=1"
                frameborder="0"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen
                style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
            </iframe>
        `;
    }

    /**
     * Create Vimeo iframe
     */
    function createVimeoIframe(videoId, containerId) {
        return `
            <iframe
                id="${containerId}"
                width="100%"
                height="100%"
                src="https://player.vimeo.com/video/${videoId}?autoplay=1&title=0&byline=0&portrait=0"
                frameborder="0"
                allow="autoplay; fullscreen; picture-in-picture"
                allowfullscreen
                style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
            </iframe>
        `;
    }

    /**
     * Create SoundCloud iframe
     */
    function createSoundCloudIframe(trackUrl, containerId) {
        return `
            <iframe
                id="${containerId}"
                width="100%"
                height="100%"
                scrolling="no"
                frameborder="no"
                allow="autoplay"
                src="https://w.soundcloud.com/player/?url=${trackUrl}&auto_play=true&hide_related=true&show_comments=false&show_user=true&show_reposts=false&show_teaser=false&visual=true"
                style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">
            </iframe>
        `;
    }

    /**
     * Initialize privacy layers
     */
    function initPrivacyLayers() {
        const privacyLayers = document.querySelectorAll('[data-vidply-privacy]');
        
        privacyLayers.forEach(layer => {
            const button = layer.querySelector('.vidply-privacy-button');
            if (!button) return;

            button.addEventListener('click', function() {
                const service = layer.getAttribute('data-vidply-privacy');
                const url = layer.getAttribute('data-vidply-url');
                const containerId = layer.getAttribute('data-vidply-id');

                if (!url || !containerId) {
                    console.error('VidPlay Privacy Layer: Missing URL or container ID');
                    return;
                }

                // Set consent for this service (shared state)
                window.VidPlyPrivacyConsent.setConsent(service);

                let iframeHtml = '';

                switch (service) {
                    case 'youtube':
                        const youtubeId = getYouTubeId(url);
                        if (youtubeId) {
                            iframeHtml = createYouTubeIframe(youtubeId, containerId);
                        }
                        break;

                    case 'vimeo':
                        const vimeoId = getVimeoId(url);
                        if (vimeoId) {
                            iframeHtml = createVimeoIframe(vimeoId, containerId);
                        }
                        break;

                    case 'soundcloud':
                        const soundcloudUrl = getSoundCloudUrl(url);
                        if (soundcloudUrl) {
                            iframeHtml = createSoundCloudIframe(soundcloudUrl, containerId);
                        }
                        break;

                    default:
                        console.error('VidPlay Privacy Layer: Unknown service', service);
                        return;
                }

                if (iframeHtml) {
                    // Replace privacy layer with iframe (16:9 aspect ratio for all services)
                    layer.style.position = 'relative';
                    layer.style.paddingBottom = '56.25%'; // 16:9 aspect ratio
                    layer.style.height = '0';
                    layer.style.width = '100%';
                    layer.innerHTML = iframeHtml;
                } else {
                    console.error('VidPlay Privacy Layer: Failed to create iframe for', service);
                }
            });

            // Add VidPlay play overlay hover effect (exact behavior)
            button.addEventListener('mouseenter', function() {
                button.style.transform = 'scale(1.1)';
            });

            button.addEventListener('mouseleave', function() {
                button.style.transform = 'scale(1)';
            });

            // Add focus styling for accessibility
            button.addEventListener('focus', function() {
                button.style.outline = '3px solid rgba(255, 255, 255, 0.6)';
                button.style.outlineOffset = '4px';
            });

            button.addEventListener('blur', function() {
                button.style.outline = 'none';
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
