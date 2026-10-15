/**
 * Initializes a single (non-playlist) media element.
 *
 * The player class is passed in by the entry module: the vidply bundle stays an
 * external runtime import that must be resolved relative to PlaylistInit.min.js.
 */

import {isSafeUrl} from '../shared/url-safety.js';
import {suppressVidPlyLogs} from './debug.js';
import {setupHLSSubtitleTracking, setupHLSTrackRefresh} from './hls-tracks.js';
import {initializedPlayers, trackPlayer} from './player-registry.js';

/**
 * Initialize a single media element
 */
export function initializeSingleElement(element, Player) {
    if (initializedPlayers.has(element)) return;

    try {
        const externalSrc = element.dataset.vidplySrc;
        let isHLS = externalSrc?.includes('.m3u8');
        let isDASH = externalSrc?.includes('.mpd');

        if (isHLS) {
            const source = document.createElement('source');
            source.src = externalSrc;
            source.type = 'application/x-mpegURL';
            element.appendChild(source);
        } else if (isDASH) {
            const source = document.createElement('source');
            source.src = externalSrc;
            source.type = 'application/dash+xml';
            element.appendChild(source);
        } else if (externalSrc && isSafeUrl(externalSrc)) {
            element.src = externalSrc;
        }

        if (!isHLS && !isDASH) {
            const sourceEls = element.querySelectorAll('source');
            for (const s of sourceEls) {
                const t = (s.type || '').toLowerCase();
                const u = (s.src || '').toLowerCase();
                if (!isHLS && (t === 'application/x-mpegurl' || t === 'application/vnd.apple.mpegurl' || u.includes('.m3u8'))) {
                    isHLS = true;
                }
                if (!isDASH && (t === 'application/dash+xml' || u.includes('.mpd'))) {
                    isDASH = true;
                }
                if (isHLS && isDASH) break;
            }
        }

        const options = element.dataset.vidplyOptions ? JSON.parse(element.dataset.vidplyOptions) : {};
        let player;
        suppressVidPlyLogs(() => {
            player = new Player(element, options);
        });

        if (isHLS) {
            setupHLSSubtitleTracking(player);
            setupHLSTrackRefresh(player);
        }

        initializedPlayers.add(element);
        element._vidplyPlayer = player;
        trackPlayer(player);
    } catch (error) {
        console.error('[VidPly Init] Error:', error);
    }
}
