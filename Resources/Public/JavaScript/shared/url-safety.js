/**
 * URL validation shared by the player init and the privacy layer.
 *
 * Both used to carry their own copy with a slightly different unsafe-character
 * set; this module is the union of the two, so the stricter rule always wins.
 */

/**
 * Characters that can break out of a CSS `url("…")` context. Whitespace is
 * rejected as well — a legitimate URL carries it percent-encoded.
 */
const CSS_URL_UNSAFE_CHARS = /["'()\\<>`\s]|[\u0000-\u001f\u007f]/;

/**
 * @param {unknown} url
 * @returns {boolean} True for an http(s) URL, absolute or relative to the page.
 */
export const isSafeUrl = (url) => {
    if (!url || typeof url !== 'string') {
        return false;
    }
    try {
        const parsed = new URL(url, window.location.origin);
        return parsed.protocol === 'https:' || parsed.protocol === 'http:';
    } catch {
        return false;
    }
};

/**
 * @param {unknown} url
 * @returns {string|null} The URL when it is safe to interpolate into `url("…")`.
 */
export const sanitizeCssUrl = (url) => {
    if (typeof url !== 'string') {
        return null;
    }
    const trimmed = url.trim();
    if (trimmed === '' || CSS_URL_UNSAFE_CHARS.test(trimmed)) {
        return null;
    }
    return isSafeUrl(trimmed) ? trimmed : null;
};
