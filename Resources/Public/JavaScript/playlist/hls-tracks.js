/**
 * Workarounds that make HLS subtitle tracks show up in the player's caption
 * menu and transcript.
 */

import {HLS_TRACK_REFRESH_DELAY} from './constants.js';

/**
 * Enable transcript/caption options when HLS subtitle tracks are discovered.
 *
 * VidPly's HLSRenderer emits 'hlssubtitletracksupdated' synchronously before
 * calling updateCaptionButtonsForHls(), which in turn calls
 * ensureTranscriptButton(). That method bails out when
 * player.options.transcriptButton is false. By patching the options inside
 * this handler we ensure the button is created.
 */
export function setupHLSSubtitleTracking(player) {
    player.on('hlssubtitletracksupdated', (data) => {
        if (data.subtitleTracks?.length > 0) {
            player.options.transcriptButton = true;
            player.options.transcript = true;
        }
    });
}

/**
 * Delayed refresh of HLS subtitle/caption state after manifest parse.
 *
 * When hls.js fires SUBTITLE_TRACKS_UPDATED, the TextTrack objects on
 * the <video> element may not be fully created yet. VidPly's internal
 * updateCaptionButtonsForHls() → captionManager.refreshTracks() can
 * therefore miss them. This delayed refresh gives hls.js time to
 * populate video.textTracks so the caption menu and transcript are
 * properly populated.
 */
export function setupHLSTrackRefresh(player) {
    player.on('hlsmanifestparsed', () => {
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));

            const subtitleCount = player.renderer?.hls?.subtitleTracks?.length || 0;
            if (subtitleCount > 0) {
                player.invalidateTrackCache?.();
                player.captionManager?.refreshTracks?.();
                player.controlBar?.ensureCaptionsButton?.();
                player.controlBar?.ensureCaptionStyleButton?.();
                player.controlBar?.ensureTranscriptButton?.();

                const tooltipContainer = player.controlBar?.rightButtons || player.controlBar?.element;
                if (tooltipContainer) {
                    player.controlBar.ensureButtonTooltips(tooltipContainer);
                }
            }
        }, HLS_TRACK_REFRESH_DELAY);
    });
}
