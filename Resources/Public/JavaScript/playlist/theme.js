/**
 * Theme Synchronization System
 * Allows VidPly players to sync with page-level theme switches (e.g. header dark/light mode toggle)
 */

import {getAlivePlayers} from './player-registry.js';

// Detect current page theme from common conventions
export function detectPageTheme() {
    const html = document.documentElement;
    const body = document.body;

    // Priority 1: Check data-bs-theme attribute (Bootstrap convention)
    const bsTheme = html.getAttribute('data-bs-theme');
    if (bsTheme === 'light') return 'light';
    if (bsTheme === 'dark') return 'dark';

    // Priority 2: Check #themeSwitch checkbox (checked = dark, unchecked = light)
    const themeSwitch = document.getElementById('themeSwitch');
    if (themeSwitch && themeSwitch.type === 'checkbox') {
        return themeSwitch.checked ? 'dark' : 'light';
    }

    // Check for common light mode indicators
    const lightModeIndicators = [
        body.classList.contains('light-mode'),
        body.classList.contains('light'),
        body.classList.contains('theme-light'),
        html.classList.contains('light-mode'),
        html.classList.contains('light'),
        html.classList.contains('theme-light'),
        body.dataset.theme === 'light',
        html.dataset.theme === 'light',
    ];

    // Check for explicit dark mode
    const darkModeIndicators = [
        body.classList.contains('dark-mode'),
        body.classList.contains('dark'),
        body.classList.contains('theme-dark'),
        html.classList.contains('dark-mode'),
        html.classList.contains('dark'),
        html.classList.contains('theme-dark'),
        body.dataset.theme === 'dark',
        html.dataset.theme === 'dark',
    ];

    if (lightModeIndicators.some(Boolean)) return 'light';
    if (darkModeIndicators.some(Boolean)) return 'dark';

    // Fallback: check prefers-color-scheme media query
    if (window.matchMedia?.('(prefers-color-scheme: light)')?.matches) return 'light';

    return 'dark'; // Default to dark
}

const THEME_SYNC_SELECTOR = '[data-vidply-theme-sync="1"]';
const PLAYER_HOST_SELECTOR = '[data-vidply-player], [data-vidply-init], [data-playlist]';

/** Theme last applied by the automatic sync, so unrelated mutations are ignored. */
let lastSyncedTheme = null;

// Theme sync is opt-in per player (extension setting themeSyncEnabled).
export function isThemeSyncEnabled() {
    return document.querySelector(THEME_SYNC_SELECTOR) !== null;
}

/**
 * Apply a theme to VidPly players.
 *
 * The automatic sync only touches players that opted in through
 * `data-vidply-theme-sync="1"` on their wrapper and only when the theme actually
 * changed; players with a fixed theme are left alone. The public
 * `VidPlyTheme.setTheme()` is an explicit request and reaches every player.
 *
 * @param {string} theme
 * @param {{ onlySynced?: boolean, force?: boolean }} [options]
 */
export function applyTheme(theme, options = {}) {
    const validTheme = theme === 'light' ? 'light' : 'dark';
    const onlySynced = options.onlySynced === true;
    if (onlySynced && !options.force && validTheme === lastSyncedTheme) {
        return;
    }
    if (onlySynced) {
        lastSyncedTheme = validTheme;
    }

    const roots = onlySynced ? Array.from(document.querySelectorAll(THEME_SYNC_SELECTOR)) : [document.documentElement];
    const inScope = (node) => node instanceof Node && roots.some((root) => root.contains(node));
    const themed = new Set();
    const setPlayerTheme = (player) => {
        if (!player || themed.has(player) || typeof player.setTheme !== 'function') {
            return;
        }
        themed.add(player);
        try {
            player.setTheme(validTheme);
        } catch (e) {
            // Ignore errors
        }
    };

    getAlivePlayers().forEach((player) => {
        if (inScope(player?.container ?? player?.element)) {
            setPlayerTheme(player);
        }
    });

    roots.forEach((root) => {
        root.querySelectorAll('.vidply-player').forEach((container) => {
            container.classList.remove('vidply-theme-dark', 'vidply-theme-light');
            container.classList.add(`vidply-theme-${validTheme}`);
        });
        root.querySelectorAll(PLAYER_HOST_SELECTOR).forEach((element) => {
            setPlayerTheme(element._vidplyPlaylist?.player ?? element._vidplyPlayer);
        });
    });

    // Dispatch event for any custom integrations
    document.dispatchEvent(new CustomEvent('vidply:themechange', {
        detail: { theme: validTheme }
    }));
}

const syncTheme = (theme, force = false) => applyTheme(theme, { onlySynced: true, force });

// Setup theme sync observers and event listeners
export function setupThemeSync() {
    const html = document.documentElement;
    const themeSwitch = document.getElementById('themeSwitch');

    // Set initial theme based on current page state
    setTimeout(() => syncTheme(detectPageTheme(), true), 200);

    // Class and data attribute changes on <html>/<body> are frequent (menus,
    // scroll state, …); only a change of the detected theme is applied.
    const observer = new MutationObserver((mutations) => {
        if (mutations.some((mutation) => mutation.attributeName === 'data-bs-theme')) {
            const newTheme = html.getAttribute('data-bs-theme');
            if (newTheme === 'light' || newTheme === 'dark') {
                syncTheme(newTheme);
                return;
            }
        }
        syncTheme(detectPageTheme());
    });

    observer.observe(html, {
        attributes: true,
        attributeFilter: ['data-bs-theme', 'class', 'data-theme']
    });

    observer.observe(document.body, {
        attributes: true,
        attributeFilter: ['class', 'data-theme', 'data-color-scheme']
    });

    // Fallback: observe #themeSwitch when data-bs-theme is not used
    if (themeSwitch && themeSwitch.type === 'checkbox') {
        themeSwitch.addEventListener('change', () => {
            // checked = dark, unchecked = light
            syncTheme(themeSwitch.checked ? 'dark' : 'light');
        });
    }

    // Listen for custom theme change events
    document.addEventListener('theme:change', (e) => {
        const theme = e.detail?.theme;
        if (theme === 'light' || theme === 'dark') {
            syncTheme(theme);
        }
    });
}
