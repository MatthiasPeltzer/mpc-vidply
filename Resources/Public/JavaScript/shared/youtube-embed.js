/**
 * YouTube privacy/consent iframe embed URL and referrer handling.
 *
 * @see https://developers.google.com/youtube/terms/required-minimum-functionality#embedded-player-api-client-identity
 */

import { isIOS, isLikelyUnsupportedYoutubeEmbedHost, shouldUseYoutubeIosLanFallback } from './platform.js';

export { shouldUseYoutubeIosLanFallback };

/**
 * @param {string} videoId
 * @returns {string}
 */
export function buildYoutubeWatchUrl(videoId) {
    return `https://www.youtube.com/watch?v=${encodeURIComponent(videoId)}`;
}

/**
 * @param {string} videoId
 * @param {{ autoplay?: boolean }} [options]
 * @returns {string}
 */
export function buildYoutubePrivacyEmbedUrl(videoId, { autoplay = false } = {}) {
    const params = new URLSearchParams({
        autoplay: autoplay ? '1' : '0',
        playsinline: '1',
        rel: '0',
        modestbranding: '1'
    });

    // origin/enablejsapi on LAN or IP origins makes iPhone playback fail at play time.
    if (!isLikelyUnsupportedYoutubeEmbedHost() && typeof window !== 'undefined') {
        params.set('enablejsapi', '1');
        if (window.location?.origin) {
            params.set('origin', window.location.origin);
        }
        if (window.location?.href) {
            params.set('widget_referrer', window.location.href);
        }
    }

    const host = isIOS()
        ? 'https://www.youtube.com/embed'
        : 'https://www.youtube-nocookie.com/embed';

    return `${host}/${videoId}?${params.toString()}`;
}

/**
 * @param {HTMLIFrameElement} iframe
 */
export function applyYoutubeEmbedReferrerPolicy(iframe) {
    iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
}

function lanFallbackCopy() {
    const lang = (typeof document !== 'undefined' ? document.documentElement.lang : '') || '';
    if (lang.toLowerCase().startsWith('de')) {
        return {
            link: 'Video in YouTube abspielen',
            hint: 'Der eingebettete Player ist auf dem iPhone bei Entwicklungs-Adressen (LAN, IP, sslip.io, DDEV, …) nicht verfügbar. Bitte YouTube öffnen.'
        };
    }
    return {
        link: 'Play video in YouTube',
        hint: 'The embedded player is not available on iPhone on development URLs (LAN, IP, sslip.io, DDEV, …). Please open YouTube.'
    };
}

/**
 * @param {Document} doc
 * @param {string} videoId
 * @returns {HTMLElement}
 */
export function createYoutubeIosLanFallback(doc, videoId) {
    const { link, hint } = lanFallbackCopy();
    const wrap = doc.createElement('div');
    wrap.className = 'vidply-youtube-ios-lan-fallback';

    const openLink = doc.createElement('a');
    openLink.href = buildYoutubeWatchUrl(videoId);
    openLink.className = 'vidply-youtube-ios-lan-fallback__link btn btn-light';
    openLink.target = '_blank';
    openLink.rel = 'noopener noreferrer';
    openLink.textContent = link;

    const sr = doc.createElement('span');
    sr.className = 'vidply-sr-only';
    const pageLang = (doc.documentElement.lang || '').toLowerCase();
    sr.textContent = pageLang.startsWith('de') ? ' (öffnet in neuem Fenster)' : ' (opens in new window)';
    openLink.appendChild(sr);

    const message = doc.createElement('p');
    message.className = 'vidply-youtube-ios-lan-fallback__hint';
    message.textContent = hint;

    wrap.appendChild(openLink);
    wrap.appendChild(message);
    return wrap;
}
