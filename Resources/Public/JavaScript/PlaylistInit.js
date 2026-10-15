/**
 * VidPly Initialization for TYPO3
 * Handles both single players and playlists, including mixed media playlists
 * with privacy consent for external services (YouTube, Vimeo, SoundCloud)
 *
 * The feature code lives in ./playlist/*; this entry scans the DOM, exposes the
 * public window.VidPlyInit / window.VidPlyTheme helpers and owns the import of
 * the vidply bundle, which stays an external runtime import next to the
 * bundled PlaylistInit.min.js.
 */

import {Player, PlaylistManager} from './vidply/vidply.esm.min.js';
import {bootstrap, resolveScope} from './shared/bootstrap.js';
import {applyWrapperPlayIconVar, observeVidplyOverlays} from './playlist/play-icon.js';
import {getAlivePlayers} from './playlist/player-registry.js';
import {initializePlaylistElement} from './playlist/playlist-player.js';
import {initializeSingleElement} from './playlist/single-player.js';
import {applyTheme, detectPageTheme, isThemeSyncEnabled, setupThemeSync} from './playlist/theme.js';

/**
 * Scan a DOM subtree for VidPly elements and initialize any that are not ready yet.
 * Used on DOMContentLoaded and after Vue/Swiper re-injects slide HTML via v-html.
 *
 * @param {ParentNode} [root=document]
 * @param {{ includeDuplicateSlides?: boolean }} [options]
 */
function scanAndInitialize(root = document, options = {}) {
    const scope = resolveScope(root);
    const includeDuplicateSlides = options.includeDuplicateSlides === true;
    const shouldSkip = (element) => {
        if (includeDuplicateSlides) {
            return false;
        }
        return element.closest('.swiper-slide-duplicate') !== null;
    };

    scope.querySelectorAll('[data-vidply-init]:not([data-playlist])').forEach((element) => {
        if (!shouldSkip(element)) {
            initializeSingleElement(element, Player);
        }
    });

    scope.querySelectorAll('[data-playlist]:not([data-vidply])').forEach((element) => {
        if (!shouldSkip(element)) {
            initializePlaylistElement(element, {Player, PlaylistManager});
        }
    });

    scope.querySelectorAll('.vidply-wrapper[data-vidply-play-icon]:not([data-vidply-play-inline-svg])').forEach(applyWrapperPlayIconVar);
    scope.querySelectorAll('.vidply-wrapper[data-vidply-play-inline-svg]').forEach(observeVidplyOverlays);
}

/**
 * Pause initialized players that live outside the given slide/container.
 *
 * @param {Element} container
 */
function pausePlayersOutside(container) {
    if (!(container instanceof Element)) {
        return;
    }

    getAlivePlayers().forEach((player) => {
        const host = player?.element;
        if (!host || container.contains(host)) {
            return;
        }

        try {
            player.pause?.();
        } catch {
            // Ignore pause errors during slide transitions.
        }
    });
}

function catchUpVueContainers() {
    document.querySelectorAll('[data-container="vue"].swiper-vue-ready').forEach((root) => {
        scanAndInitialize(root);
    });
}

function runInitialSetup() {
    scanAndInitialize(document);

    if (isThemeSyncEnabled()) {
        setupThemeSync();
    }

    catchUpVueContainers();
}

// Export theme and init helpers for external use (Vue sliders, galleries, …)
window.VidPlyTheme = {
    setTheme: (theme) => applyTheme(theme),
    getPlayers: () => getAlivePlayers(),
    detectPageTheme
};

window.VidPlyInit = {
    scan: scanAndInitialize,
    pauseOutside: pausePlayersOutside
};

bootstrap((root) => scanAndInitialize(root), {onReady: runInitialSetup});
