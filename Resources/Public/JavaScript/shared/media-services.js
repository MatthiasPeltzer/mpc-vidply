/**
 * Detection of the external media services that need a privacy consent step.
 */

export const KNOWN_SERVICES = new Set(['youtube', 'vimeo', 'soundcloud']);

const URL_PATTERNS = {
    youtube: [
        /(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&?/]+)/,
        /youtube\.com\/embed\/([^&?/]+)/,
        /youtube\.com\/v\/([^&?/]+)/,
    ],
    vimeo: /vimeo\.com\/(?:video\/)?(\d+)/,
};

/**
 * @param {string|null|undefined} src
 * @returns {'youtube'|'vimeo'|'soundcloud'|null}
 */
export const getServiceType = (src) => {
    if (!src) {
        return null;
    }
    const value = src.toLowerCase();
    if (value.includes('youtube.com') || value.includes('youtu.be')) {
        return 'youtube';
    }
    if (value.includes('vimeo.com')) {
        return 'vimeo';
    }
    if (value.includes('soundcloud.com')) {
        return 'soundcloud';
    }
    return null;
};

/**
 * True for sources the player hands to a renderer of its own — the consent
 * services plus HLS, whose errors must not trigger the auto-advance fallback.
 *
 * @param {string|null|undefined} src
 */
export const isExternalRendererUrl = (src) => {
    if (!src) {
        return false;
    }
    return getServiceType(src) !== null || src.toLowerCase().includes('.m3u8');
};

/**
 * The provider-specific id an embed URL is built from.
 *
 * @param {string} url
 * @param {string|null} service
 * @returns {string|null}
 */
export const extractMediaId = (url, service) => {
    if (service === 'youtube') {
        for (const pattern of URL_PATTERNS.youtube) {
            const match = url.match(pattern);
            if (match?.[1]) {
                return match[1];
            }
        }
        return null;
    }

    if (service === 'vimeo') {
        return url.match(URL_PATTERNS.vimeo)?.[1] || null;
    }

    if (service === 'soundcloud') {
        return encodeURIComponent(url);
    }

    return null;
};
