/**
 * VidPly Initialization for TYPO3
 * Handles both single players and playlists
 * Manual initialization to prevent double-init bug
 */

import {Player, PlaylistManager} from './vidply/vidply.esm.min.js';

// Track initialized players to prevent double-init
const initializedPlayers = new WeakSet();

// Prepare HLS elements and manually initialize with VidPly
// Both this and vidply.esm.min.js are modules, so they defer - we can't rely on auto-init timing
document.addEventListener('DOMContentLoaded', function prepareAndInitHLS() {
    const elements = document.querySelectorAll('[data-vidply-init]:not([data-playlist])');
    elements.forEach((element) => {
        const externalSrc = element.dataset.vidplySrc;
        if (externalSrc && externalSrc.includes('.m3u8')) {
            const posterUrl = element.getAttribute('poster');

            // Add a source element with a proper MIME type
            const source = document.createElement('source');
            source.setAttribute('src', externalSrc);
            source.setAttribute('type', 'application/x-mpegURL');
            element.appendChild(source);

            // console.log('[VidPly Init] Added source element for HLS:', externalSrc);

            // Restore poster
            if (posterUrl) {
                element.setAttribute('poster', posterUrl);
            }

            // Set data-vidply for VidPly's detection
            element.removeAttribute('data-vidply-init');
            element.setAttribute('data-vidply', '');

            // Parse options
            const options = element.dataset.vidplyOptions ? JSON.parse(element.dataset.vidplyOptions) : {};

            // Manually initialize with VidPly (since auto-init won't work with module timing)
            // console.log('[VidPly Init] Manually initializing HLS player');
            const player = new Player(element, options);
            element._vidplyPlayer = player;
            initializedPlayers.add(element);

            // console.log('[VidPly Init] HLS player initialized, renderer:', player.renderer?.constructor.name);
        }
    });
});

document.addEventListener('DOMContentLoaded', () => {
    // Initialize NON-HLS media elements with manual init
    const singleElements = document.querySelectorAll('[data-vidply-init]:not([data-playlist])');

    singleElements.forEach((element, index) => {
        if (initializedPlayers.has(element)) {
            return;
        }

        try {
            // Handle external URLs (HLS, YouTube, Vimeo) from data-vidply-src
            const externalSrc = element.dataset.vidplySrc;
            const isHLS = externalSrc && externalSrc.includes('.m3u8');

            let player;

            // For HLS streams, add a source element (Firefox needs MIME type)
            if (isHLS) {
                const posterUrl = element.getAttribute('poster');

                // Add a source element with a proper MIME type
                const source = document.createElement('source');
                source.setAttribute('src', externalSrc);
                source.setAttribute('type', 'application/x-mpegURL');
                element.appendChild(source);

                // console.log('[VidPly Init] Added source element for HLS');

                // Restore poster
                if (posterUrl) {
                    element.setAttribute('poster', posterUrl);
                }

                // Create player
                const options = element.dataset.vidplyOptions ? JSON.parse(element.dataset.vidplyOptions) : {};
                player = new Player(element, options);
                // console.log('[VidPly Init] Player created for HLS');

                // Chrome workaround: VidPly doesn't detect HLS from a source element in manual init
                // So for Chrome, we accept HTMLRenderer and the browser handles HLS natively
                // Quality button won't be available in Chrome (browser-level quality only)
                // console.log('[VidPly Init] Note: Quality button may not appear in Chrome due to VidPly limitation');

            } else {
                // For non-HLS: set src and create player normally
                if (externalSrc) {
                    element.setAttribute('src', externalSrc);
                }

                const options = element.dataset.vidplyOptions ? JSON.parse(element.dataset.vidplyOptions) : {};
                // console.log('[VidPly Init] Creating Player (non-HLS)');
                player = new Player(element, options);
            }

            // For HLS streams, ensure quality button appears after levels are loaded
            if (isHLS) {
                // Use VidPly's event system to detect when ready
                player.on('ready', () => {
                    // console.log('[VidPly Init] Player ready, checking renderer');
                    // console.log('[VidPly Init] Renderer type:', player.renderer?.constructor.name);
                    // console.log('[VidPly Init] Has hls instance:', !!player.renderer?.hls);
                    // console.log('[VidPly Init] Video src:', element.src);
                    // console.log('[VidPly Init] Video sources:', element.querySelectorAll('source').length);

                    // Check multiple times as hls.js might still be initializing
                    const checkQuality = (attempts = 0) => {
                        if (attempts > 10) {
                            // console.log('[VidPly Init] Gave up waiting for quality levels');
                            // console.log('[VidPly Init] Final renderer:', player.renderer?.constructor.name);
                            // console.log('[VidPly Init] Final hls:', player.renderer?.hls);
                            return;
                        }

                        if (player.renderer?.hls?.levels?.length > 0) {
                            // console.log('[VidPly Init] Quality levels detected:', player.renderer.hls.levels.length);
                            // Force control bar to rebuild with the quality button
                            if (player.controls?.buildControlBar) {
                                player.controls.buildControlBar();
                            }
                            window.dispatchEvent(new Event('resize'));
                        } else {
                            // console.log('[VidPly Init] No quality levels yet, retrying...', attempts);
                            setTimeout(() => checkQuality(attempts + 1), 500);
                        }
                    };

                    setTimeout(() => checkQuality(), 500);
                });
            }

            initializedPlayers.add(element);
            element._vidplyPlayer = player;
        } catch (error) {
            // console.error('[VidPly Init] Error:', error);
        }
    });

    // Initialize playlist elements
    const playlistElements = document.querySelectorAll('[data-playlist]:not([data-vidply])');

    playlistElements.forEach((element, index) => {
        try {
            if (!element.id) return;

            // Parse playlist data
            const tracks = element.dataset.playlist ? JSON.parse(element.dataset.playlist) : [];
            if (!tracks || tracks.length === 0) return;

            // Parse player options
            const options = element.dataset.vidplyOptions ? JSON.parse(element.dataset.vidplyOptions) : {};

            // Determine media type
            const mediaType = element.tagName === 'AUDIO' ? 'audio' : 'video';

            // Create a player first
            const player = new Player(element, {
                ...options,
                mediaType
            });

            // Wait for the player to be ready before creating a playlist
            // This ensures the player's container exists for the panel
            player.on('ready', () => {
                // Parse playlist options from attributes
                const playlistOptions = {
                    autoAdvance: element.dataset.playlistAutoAdvance !== 'false',
                    autoPlayFirst: element.dataset.playlistAutoPlayFirst === 'true',
                    loop: element.dataset.playlistLoop === 'true',
                    showPanel: element.dataset.playlistShowPanel !== 'false',
                    tracks  // Pass tracks directly in options
                };

                // Create playlist manager after player is ready
                const playlist = new PlaylistManager(player, playlistOptions);

                element._vidplyPlaylist = playlist;

                // Listen for track changes
                player.on('playlisttrackchange', (e) => {
                    // console.log(`Now playing: ${e.item?.title || 'Unknown'}`);
                });
            });

            initializedPlayers.add(element);
            element._vidplyPlayer = player;

        } catch (error) {
            console.error('[VidPly Init] Playlist error:', error);
        }
    });
});

