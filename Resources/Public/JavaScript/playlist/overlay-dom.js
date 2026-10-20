/**
 * DOM helpers around the playlist privacy overlay: where it is inserted, how it
 * is removed again, and hiding/restoring the player chrome and audio artwork
 * while consent is pending.
 */

/**
 * Collect DOM roots that may contain a playlist privacy overlay.
 */
function getPrivacyOverlaySearchRoots(playlist, element, wrapperElement = null) {
    const playerContainer = playlist?.player?.container;
    const main = playerContainer?.querySelector('.vidply-playlist-main');

    return [
        main,
        playerContainer,
        wrapperElement,
        element.closest?.('.vidply-wrapper'),
        element.parentElement,
        element
    ].filter(Boolean);
}

/**
 * Remove privacy overlay nodes without restoring player visibility.
 */
export function removePrivacyOverlayNodes(playlist, element, wrapperElement = null) {
    getPrivacyOverlaySearchRoots(playlist, element, wrapperElement).forEach(root => {
        root.querySelectorAll?.('.vidply-playlist-privacy-overlay').forEach(node => node.remove());
    });
}

/**
 * Remove privacy overlay and restore hidden elements
 */
export function removePrivacyOverlay(element, playlist, wrapperElement = null) {
    removePrivacyOverlayNodes(playlist, element, wrapperElement);

    const currentPlayer = playlist.player;
    if (currentPlayer?.videoWrapper) {
        currentPlayer.videoWrapper.style.display = '';
        currentPlayer.videoWrapper.removeAttribute('data-vidply-hidden');
    }

    // `element` can be an <audio> which cannot contain children; restore from a broader scope.
    const scopeRoots = [
        currentPlayer?.container,
        currentPlayer?.videoWrapper?.parentElement,
        element.closest('.vidply-wrapper'),
        element.parentElement,
        element
    ].filter(Boolean);

    for (const root of scopeRoots) {
        root.querySelectorAll?.('[data-vidply-hidden]').forEach(el => {
            el.style.display = '';
            el.removeAttribute('data-vidply-hidden');
        });
    }
}

/**
 * Find the track artwork element, even when the playlist host is an <audio> element.
 */
function findTrackArtworkElement(playlist, element, wrapperElement = null) {
    const player = playlist?.player;
    const candidates = [
        element?.querySelector?.('.vidply-playlist-main'),
        player?.container?.querySelector?.('.vidply-playlist-main'),
        player?.container,
        player?.videoWrapper?.parentElement,
        wrapperElement,
        element.closest?.('.vidply-wrapper'),
        element.parentElement,
        element
    ].filter(Boolean);

    for (const root of candidates) {
        const artwork = root.querySelector?.('.vidply-track-artwork');
        if (artwork) return artwork;
    }
    return null;
}

/**
 * Force-hide the audio artwork when switching to external renderers (YouTube/Vimeo/SoundCloud),
 * even if VidPly keeps using an <audio> host element internally.
 */
export function setArtworkForcedHidden(playlist, element, wrapperElement, shouldHide) {
    const artwork = findTrackArtworkElement(playlist, element, wrapperElement);
    if (!artwork) return;

    if (shouldHide) {
        artwork.setAttribute('data-vidply-artwork-forced-hidden', 'true');
        artwork.style.setProperty('display', 'none');
        artwork.setAttribute('aria-hidden', 'true');
        return;
    }

    if (artwork.getAttribute('data-vidply-artwork-forced-hidden') === 'true') {
        artwork.removeAttribute('data-vidply-artwork-forced-hidden');
        artwork.style.removeProperty('display');
        artwork.removeAttribute('aria-hidden');
    }
}

/**
 * Pause player and hide elements before showing consent overlay
 */
export function pauseAndHidePlayer(playlist, element, wrapperElement = null) {
    const currentPlayer = playlist.player;
    if (!currentPlayer) return;

    try {
        currentPlayer.pause();
        if (currentPlayer.videoWrapper) {
            currentPlayer.videoWrapper.style.display = 'none';
            currentPlayer.videoWrapper.setAttribute('data-vidply-hidden', 'true');
        }
        const artwork = findTrackArtworkElement(playlist, element, wrapperElement);
        if (artwork) {
            artwork.setAttribute('data-vidply-hidden', 'true');
            artwork.style.setProperty('display', 'none');
        }
    } catch (e) {
        // Ignore errors
    }
}

/**
 * Restore visibility after consent
 */
export function restorePlayerVisibility(playlist, element, wrapperElement = null) {
    const player = playlist.player;
    if (player?.videoWrapper) {
        player.videoWrapper.style.display = '';
        player.videoWrapper.removeAttribute('data-vidply-hidden');
    }

    const scopeRoots = [
        player?.container,
        player?.videoWrapper?.parentElement,
        wrapperElement,
        element.closest?.('.vidply-wrapper'),
        element.parentElement,
        element
    ].filter(Boolean);

    for (const root of scopeRoots) {
        root.querySelectorAll?.('[data-vidply-hidden]').forEach(el => {
            el.style.display = '';
            el.removeAttribute('data-vidply-hidden');
        });
    }
}

/**
 * Hide the VidPly control bar, play overlay and spinner for an external track.
 * The YouTube/Vimeo/SoundCloud renderers only add this class once their SDK and
 * iframe are ready; without it the HTML5 chrome shows while they load. The
 * HTML5/HLS/DASH renderers remove the class again when switching back.
 */
export function hideControlsForExternalEmbed(playlist) {
    const player = playlist?.player;
    if (!player?.container) {
        return;
    }
    if (player.options?.embedChrome === 'provider') {
        player.container.classList.add('vidply-external-controls');
        return;
    }
    if (typeof player.syncEmbedChromeClass === 'function') {
        player.syncEmbedChromeClass();
    } else {
        player.container.classList.remove('vidply-external-controls');
    }
}

/**
 * Find where the privacy overlay should sit so it covers the media area only.
 */
function findOverlayInsertionPoint(playlist, element, wrapperElement = null) {
    const playerContainer = playlist?.player?.container;
    const main = playerContainer?.querySelector('.vidply-playlist-main');
    const videoWrapper = main?.querySelector('.vidply-video-wrapper')
        ?? playerContainer?.querySelector('.vidply-video-wrapper');

    if (videoWrapper?.parentElement) {
        return { parent: videoWrapper.parentElement, before: videoWrapper };
    }

    if (main) {
        return { parent: main, before: main.firstChild };
    }

    if (playerContainer) {
        const playerChild = playerContainer.querySelector('.vidply-video-wrapper, .vidply-playlist-main');
        if (playerChild?.parentElement === playerContainer) {
            return { parent: playerContainer, before: playerChild };
        }
        return { parent: playerContainer, before: playerContainer.firstChild };
    }

    const scopeRoots = getPrivacyOverlaySearchRoots(playlist, element, wrapperElement);
    for (const root of scopeRoots) {
        for (const child of root.children ?? []) {
            if (child.classList.contains('vidply-video-wrapper') || child.classList.contains('vidply-player')) {
                return { parent: root, before: child };
            }
        }
    }

    return { parent: element, before: element.firstChild };
}

/**
 * Insert privacy overlay into DOM
 */
export function insertPrivacyOverlay(overlay, playlist, element, wrapperElement = null) {
    const { parent, before } = findOverlayInsertionPoint(playlist, element, wrapperElement);
    if (parent) {
        if (before) {
            parent.insertBefore(overlay, before);
        } else {
            parent.appendChild(overlay);
        }
        return;
    }

    element.appendChild(overlay);
}
