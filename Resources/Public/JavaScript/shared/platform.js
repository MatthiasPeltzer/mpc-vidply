/**
 * Platform helpers shared by PrivacyLayer and PlaylistInit.
 */

/** iOS / iPadOS (including iPad desktop mode reporting as MacIntel). */
export function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

/** iPhone / iPod touch only (excludes iPad). */
export function isIPhone() {
    return /iPhone|iPod/.test(navigator.userAgent);
}

/**
 * Hostnames where YouTube's iOS embed identity checks fail (LAN IP, localhost, …).
 * Normal HTTPS domains are not included.
 *
 * @param {string} [hostname]
 */
/** Wildcard-DNS / tunnel hostnames that still point at LAN — YouTube rejects iPhone embeds there too. */
const DEV_TUNNEL_HOST_SUFFIXES = [
    '.sslip.io',
    '.nip.io',
    '.xip.io',
    '.ddev.site',
    '.ddev.local',
    '.docker.internal'
];

export function isLikelyUnsupportedYoutubeEmbedHost(hostname = (typeof window !== 'undefined' ? window.location.hostname : '')) {
    if (!hostname) {
        return true;
    }
    const host = hostname.toLowerCase();
    if (host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.local')) {
        return true;
    }
    if (DEV_TUNNEL_HOST_SUFFIXES.some((suffix) => host === suffix.slice(1) || host.endsWith(suffix))) {
        return true;
    }
    // sslip.io / nip.io encode the IP in the label, e.g. mpcore.192-168-178-75.sslip.io
    if (/\d{1,3}-\d{1,3}-\d{1,3}-\d{1,3}/.test(host)) {
        return true;
    }
    if (/^(?:\d{1,3}\.){3}\d{1,3}$/.test(host)) {
        return true;
    }
    if (host.includes(':')) {
        return true;
    }
    return false;
}

/** iPhone on LAN/IP/local URLs cannot use the in-page YouTube iframe player. */
export function shouldUseYoutubeIosLanFallback() {
    return isIPhone() && isLikelyUnsupportedYoutubeEmbedHost();
}
