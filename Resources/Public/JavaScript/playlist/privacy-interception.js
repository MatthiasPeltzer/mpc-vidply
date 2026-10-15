/**
 * Privacy consent for playlists with external media: patches the playlist's
 * loadTrack()/play() so an external track only loads after the visitor
 * accepted the provider's privacy policy.
 */

import {privacyConsent} from '../shared/consent.js';
import {ensureAutoplay} from './autoplay.js';
import {suppressVidPlyLogs} from './debug.js';
import {
    hideControlsForExternalEmbed,
    insertPrivacyOverlay,
    pauseAndHidePlayer,
    removePrivacyOverlay,
    removePrivacyOverlayNodes,
    restorePlayerVisibility,
    setArtworkForcedHidden
} from './overlay-dom.js';
import {getInlinePlaySvgMarkup} from './play-icon.js';
import {createPrivacyOverlay} from './privacy-overlay.js';
import {resolveTrackServiceType} from './track-types.js';

/**
 * Show consent overlay then proceed with track loading
 */
function showConsentOverlay(playlist, element, wrapperElement, serviceType, track, index, proceedFn, privacySettings = null) {
    // Drop any previous overlay, then hide the current media chrome for this consent step.
    removePrivacyOverlayNodes(playlist, element, wrapperElement);
    pauseAndHidePlayer(playlist, element, wrapperElement);

    // Update playlist UI
    playlist.currentIndex = index;
    playlist.updatePlaylistUI?.();
    playlist.updateTrackInfo?.(track);

    // External services should never show the audio artwork (it duplicates the privacy layer poster).
    setArtworkForcedHidden(playlist, element, wrapperElement, true);
    requestAnimationFrame(() => setArtworkForcedHidden(playlist, element, wrapperElement, true));

    // Create and insert overlay
    const playIconUrl = wrapperElement?.dataset?.vidplyPlayIcon || element?.dataset?.vidplyPlayIcon || null;
    const playButtonPosition = wrapperElement?.dataset?.vidplyPlayPosition || element?.dataset?.vidplyPlayPosition || 'center';
    const playIconInlineSvg = getInlinePlaySvgMarkup(wrapperElement);
    const headlineLevel = Number.parseInt(element?.dataset?.playlistPrivacyHeadlineLevel ?? '', 10);
    const overlay = createPrivacyOverlay(serviceType, track, () => {
        restorePlayerVisibility(playlist, element, wrapperElement);
        hideControlsForExternalEmbed(playlist);
        proceedFn();
        ensureAutoplay(playlist);
    }, privacySettings, playIconUrl, playButtonPosition, playIconInlineSvg, headlineLevel);

    insertPrivacyOverlay(overlay, playlist, element, wrapperElement);
}

/**
 * Intercept track loading to handle privacy consent
 */
function createTrackInterceptor(playlist, element, wrapperElement, originalFn, privacySettings = null, applyPerTrackUi = null) {
    return (index, userInitiated) => {
        const track = playlist.tracks[index];
        if (!track) return originalFn(index, userInitiated);

        // Apply per-track UI overrides before switching sources.
        if (typeof applyPerTrackUi === 'function') {
            applyPerTrackUi(track);
        }

        const serviceType = resolveTrackServiceType(track);

        // Keep audio artwork hidden for external tracks; allow VidPly to manage it otherwise.
        setArtworkForcedHidden(playlist, element, wrapperElement, !!serviceType);

        if (serviceType && !privacyConsent.hasConsent(serviceType)) {
            showConsentOverlay(playlist, element, wrapperElement, serviceType, track, index,
                () => originalFn(index, userInitiated), privacySettings);
            return;
        }

        removePrivacyOverlay(element, playlist, wrapperElement);
        if (serviceType) {
            hideControlsForExternalEmbed(playlist);
        }
        const result = originalFn(index, userInitiated);

        // User-initiated playlist.play() starts embeds once; delayed retries fight
        // the iframe API and cause stop/start loops (YouTube/Vimeo/SoundCloud).
        if (serviceType && userInitiated !== true) {
            ensureAutoplay(playlist);
        }

        return result;
    };
}

/**
 * First track is an external embed and consent is pending: show privacy overlay on load
 * instead of the default HTML5 chrome (presentIdleTrack only sets poster metadata).
 */
function showInitialPrivacyOverlayIfNeeded(
    playlist,
    element,
    wrapperElement,
    tracks,
    autoPlayFirst,
    privacySettings,
    applyPerTrackUi,
    originalPlay
) {
    if (autoPlayFirst || !Array.isArray(tracks) || tracks.length === 0) {
        return;
    }

    const track = tracks[0];
    const serviceType = resolveTrackServiceType(track);
    if (!serviceType || privacyConsent.hasConsent(serviceType)) {
        return;
    }

    if (typeof applyPerTrackUi === 'function') {
        applyPerTrackUi(track);
    }

    showConsentOverlay(
        playlist,
        element,
        wrapperElement,
        serviceType,
        track,
        0,
        () => originalPlay(0, true),
        privacySettings
    );
}

/**
 * Setup privacy consent interception for playlists with external media
 */
export function setupPrivacyInterception(playlist, element, wrapperElement, tracks, autoPlayFirst, privacySettings = null, applyPerTrackUi = null) {
    const originalLoadTrack = playlist.loadTrack.bind(playlist);
    const originalPlay = playlist.play.bind(playlist);

    // Patch methods with interceptor
    playlist.loadTrack = function (index) {
        return createTrackInterceptor(playlist, element, wrapperElement, (idx) => originalLoadTrack(idx), privacySettings, applyPerTrackUi)(index);
    };

    playlist.play = function (index, userInitiated) {
        return createTrackInterceptor(playlist, element, wrapperElement, (idx, ui) => originalPlay(idx, ui), privacySettings, applyPerTrackUi)(index, userInitiated);
    };

    // Load playlist with patched methods
    playlist.options.autoPlayFirst = autoPlayFirst;
    suppressVidPlyLogs(() => {
        playlist.loadPlaylist(tracks);
    });

    showInitialPrivacyOverlayIfNeeded(
        playlist,
        element,
        wrapperElement,
        tracks,
        autoPlayFirst,
        privacySettings,
        applyPerTrackUi,
        originalPlay
    );
}
