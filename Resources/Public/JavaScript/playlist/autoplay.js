/**
 * Starts an external playlist track after the visitor gave consent, retrying
 * while the embed is still loading.
 */

import {isIOS} from '../shared/platform.js';
import {AUTOPLAY_TIMEOUTS, OVERLAY_INSERT_DELAY} from './constants.js';

/**
 * Cancels the pending autoplay retries of a playlist. There is one chain per
 * playlist at a time, and it ends as soon as playback starts or the visitor
 * pauses — a retry must never restart media the visitor has paused
 * (WCAG 2.2.2 Pause, Stop, Hide).
 *
 * @type {WeakMap<object, () => void>}
 */
const autoplayChains = new WeakMap();

/**
 * Ensure autoplay after external content loads
 */
export function ensureAutoplay(playlist) {
    // iOS does not honour programmatic play() on cross-origin embeds; delayed
    // retries after consent only confuse YouTube's player (error panel) on iPhone.
    if (isIOS()) {
        return;
    }

    autoplayChains.get(playlist)?.();

    const timers = [];
    let currentPlayer = null;
    let cancelled = false;

    const cancel = () => {
        if (cancelled) return;
        cancelled = true;
        timers.forEach((timer) => clearTimeout(timer));
        if (currentPlayer && typeof currentPlayer.off === 'function') {
            currentPlayer.off('play', cancel);
            currentPlayer.off('pause', cancel);
            currentPlayer.off('ready', onReady);
        }
        if (autoplayChains.get(playlist) === cancel) {
            autoplayChains.delete(playlist);
        }
    };

    const tryPlay = () => {
        if (cancelled) return;
        try {
            currentPlayer?.play?.();
        } catch (e) {
            // Autoplay might be blocked by browser
        }
    };

    const schedule = (delay) => {
        if (!cancelled) {
            timers.push(setTimeout(tryPlay, delay));
        }
    };

    // For external services, wait for ready event
    const onReady = () => schedule(AUTOPLAY_TIMEOUTS[0]);

    autoplayChains.set(playlist, cancel);

    timers.push(setTimeout(() => {
        currentPlayer = playlist.player;
        if (!currentPlayer || cancelled) {
            cancel();
            return;
        }

        if (typeof currentPlayer.on === 'function') {
            currentPlayer.on('play', cancel);
            currentPlayer.on('pause', cancel);
            currentPlayer.on('ready', onReady);
        }

        // Try after delays to handle different loading times
        AUTOPLAY_TIMEOUTS.forEach(schedule);
    }, OVERLAY_INSERT_DELAY));
}
