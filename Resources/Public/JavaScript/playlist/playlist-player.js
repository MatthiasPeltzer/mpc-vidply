/**
 * Initializes playlist players, including mixed audio/video playlists and
 * playlists with external media behind the privacy consent overlay.
 *
 * The player classes are passed in by the entry module: the vidply bundle stays
 * an external runtime import that must be resolved relative to PlaylistInit.min.js.
 */

import {isExternalRendererUrl} from '../shared/media-services.js';
import {isIOS} from '../shared/platform.js';
import {ERROR_ADVANCE_DELAY} from './constants.js';
import {suppressVidPlyLogs} from './debug.js';
import {initializedPlayers, trackPlayer} from './player-registry.js';
import {setupPrivacyInterception} from './privacy-interception.js';
import {playlistHasMixedElementTypes} from './track-types.js';

/**
 * Get media type for playlist element
 */
function getMediaType(element) {
    if (element.tagName === 'DIV') {
        return element.dataset.vidplyMediaType || 'video';
    }
    return element.tagName === 'AUDIO' ? 'audio' : 'video';
}

/**
 * Resolve the DOM host used when swapping <video> ↔ <audio> in mixed playlists.
 * DIV hosts (MixedPlaylistPlayer) are used directly; media hosts get a wrapper
 * so recreation does not wipe sibling asset scripts in `.vidply-wrapper`.
 */
function resolvePlaylistHostElement(element, needsRecreate) {
    if (!needsRecreate) {
        return null;
    }
    if (element.tagName === 'DIV') {
        return element;
    }
    const hostElement = document.createElement('div');
    hostElement.className = 'vidply-playlist-host';
    if (element.id) {
        hostElement.id = `${element.id}-host`;
    }
    element.parentElement?.insertBefore(hostElement, element);
    hostElement.appendChild(element);
    return hostElement;
}

/**
 * Create patched error handler for playlist
 */
function createPlaylistErrorHandler(playlist, autoAdvance) {
    return function handleTrackError(e) {
        if (this.isChangingTrack) {
            return;
        }
        const player = this.player;
        if (player?._switchingRenderer) {
            return;
        }
        const currentTrack = this.getCurrentTrack();
        if (currentTrack?.src && isExternalRendererUrl(currentTrack.src)) {
            return;
        }
        // iOS often emits transient media errors while src/renderer is swapping;
        // auto-advance would walk the whole playlist to the last item.
        if (autoAdvance && !isIOS()) {
            setTimeout(() => this.next(), ERROR_ADVANCE_DELAY);
        }
    }.bind(playlist);
}

/**
 * Initialize a playlist element with privacy consent handling
 *
 * @param {HTMLElement} element
 * @param {{ Player: Function, PlaylistManager: Function }} classes
 */
export function initializePlaylistElement(element, {Player, PlaylistManager}) {
    if (!element.id || initializedPlayers.has(element)) return;

    try {
        const tracks = element.dataset.playlist ? JSON.parse(element.dataset.playlist) : [];
        if (!tracks.length) return;

        const options = element.dataset.vidplyOptions ? JSON.parse(element.dataset.vidplyOptions) : {};
        const hasExternalMedia = element.dataset.playlistHasExternal === 'true';
        const hasMixedElementTypes = playlistHasMixedElementTypes(tracks);
        const needsRecreate = hasExternalMedia || hasMixedElementTypes;
        const hostElement = resolvePlaylistHostElement(element, needsRecreate);
        const wrapperElement = element.closest('.vidply-wrapper') || element.parentElement;
        const mediaType = getMediaType(element);
        const autoPlayFirst = element.dataset.playlistAutoPlayFirst === 'true';
        const autoAdvance = element.dataset.playlistAutoAdvance !== 'false';
        const privacySettings = element.dataset.playlistPrivacySettings ? JSON.parse(element.dataset.playlistPrivacySettings) : null;

        // Remove data-playlist before creating player for external media playlists
        if (hasExternalMedia) {
            element.removeAttribute('data-playlist');
        }

        let player;
        suppressVidPlyLogs(() => {
            player = new Player(element, {...options, mediaType});
        });

        player.on('ready', () => {
            const playlistOptions = {
                autoAdvance,
                autoPlayFirst: hasExternalMedia ? false : autoPlayFirst,
                loop: element.dataset.playlistLoop === 'true',
                showPanel: element.dataset.playlistShowPanel !== 'false',
                panelPosition: element.dataset.playlistPanelPosition === 'right' ? 'right' : 'below',
                tracks: hasExternalMedia ? [] : tracks,
                recreatePlayers: needsRecreate && Boolean(hostElement),
                hostElement,
                PlayerClass: Player
            };

            let playlist;
            suppressVidPlyLogs(() => {
                playlist = new PlaylistManager(player, playlistOptions);
            });
            element._vidplyPlaylist = playlist;
            if (hostElement) {
                hostElement._vidplyPlaylist = playlist;
            }

            /**
             * Apply per-track UI overrides (e.g. hide speed / help buttons).
             * Updates the option and toggles the DOM element directly.
             */
            const applyPerTrackUi = (track) => {
                if (!track) return;
                const hideSpeedButton = track.hideSpeedButton === true;
                player.options.speedButton = !hideSpeedButton;
                const speedBtn = player.controlBar?.controls?.speed;
                if (speedBtn) {
                    speedBtn.style.display = hideSpeedButton ? 'none' : '';
                }

                const hideHelpButton = track.hideHelpButton === true;
                player.options.helpButton = !hideHelpButton;
                const helpBtn = player.controlBar?.controls?.help;
                if (helpBtn) {
                    helpBtn.style.display = hideHelpButton ? 'none' : '';
                }
            };

            // Ensure initial state matches the first/current track.
            try {
                applyPerTrackUi(playlist.getCurrentTrack?.() || tracks[0]);
            } catch (e) {
                // ignore
            }

            // Patch loadTrack / play so switching tracks updates UI overrides.
            // Important: setupPrivacyInterception (external media playlists) will patch these
            // methods again; to keep behavior consistent, it should call applyPerTrackUi too.
            const patchPlaylistMethodsForUi = () => {
                const originalLoadTrack = playlist.loadTrack?.bind(playlist);
                if (typeof originalLoadTrack === 'function') {
                    playlist.loadTrack = function (index) {
                        const t = playlist.tracks?.[index];
                        applyPerTrackUi(t);
                        return originalLoadTrack(index);
                    };
                }
                const originalPlay = playlist.play?.bind(playlist);
                if (typeof originalPlay === 'function') {
                    playlist.play = function (index, userInitiated) {
                        const idx = typeof index === 'number' ? index : playlist.currentIndex;
                        const t = playlist.tracks?.[idx];
                        applyPerTrackUi(t);
                        return originalPlay(index, userInitiated);
                    };
                }
            };
            patchPlaylistMethodsForUi();

            // Patch error handler
            player.off('error', playlist.handleTrackError);
            playlist.handleTrackError = createPlaylistErrorHandler(playlist, autoAdvance);
            player.on('error', playlist.handleTrackError);

            // Setup privacy interception for external media
            if (hasExternalMedia) {
                setupPrivacyInterception(playlist, element, wrapperElement, tracks, autoPlayFirst, privacySettings, applyPerTrackUi);
            }
        });

        initializedPlayers.add(element);
        element._vidplyPlayer = player;
        trackPlayer(player);
    } catch (error) {
        console.error('[VidPly Init] Playlist error:', error);
    }
}
