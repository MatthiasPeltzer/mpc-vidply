import { afterEach, describe, expect, it, vi } from 'vitest';
import { trackPlayer } from '../../../Resources/Public/JavaScript/playlist/player-registry.js';
import {
    applyTheme,
    detectPageTheme,
    isThemeSyncEnabled,
} from '../../../Resources/Public/JavaScript/playlist/theme.js';

const html = document.documentElement;

afterEach(() => {
    html.removeAttribute('data-bs-theme');
    html.removeAttribute('data-theme');
    html.className = '';
    document.body.className = '';
    document.body.removeAttribute('data-theme');
    document.body.replaceChildren();
});

describe('detectPageTheme', () => {
    it('prefers the Bootstrap data-bs-theme attribute', () => {
        html.setAttribute('data-bs-theme', 'light');
        document.body.classList.add('dark-mode');

        expect(detectPageTheme()).toBe('light');
    });

    it('reads a #themeSwitch checkbox', () => {
        document.body.innerHTML = '<input type="checkbox" id="themeSwitch">';
        expect(detectPageTheme()).toBe('light');

        document.getElementById('themeSwitch').checked = true;
        expect(detectPageTheme()).toBe('dark');
    });

    it.each([
        ['body class', () => document.body.classList.add('theme-light'), 'light'],
        ['html class', () => html.classList.add('light-mode'), 'light'],
        ['body data-theme', () => { document.body.dataset.theme = 'dark'; }, 'dark'],
        ['html class dark', () => html.classList.add('dark'), 'dark'],
    ])('detects the theme from %s', (_label, arrange, expected) => {
        arrange();
        expect(detectPageTheme()).toBe(expected);
    });

    it('falls back to prefers-color-scheme, then dark', () => {
        const matchMedia = vi.fn().mockReturnValue({ matches: true });
        vi.stubGlobal('matchMedia', matchMedia);
        expect(detectPageTheme()).toBe('light');

        matchMedia.mockReturnValue({ matches: false });
        expect(detectPageTheme()).toBe('dark');
        vi.unstubAllGlobals();
    });
});

describe('isThemeSyncEnabled', () => {
    it('is opt-in through data-vidply-theme-sync', () => {
        expect(isThemeSyncEnabled()).toBe(false);

        document.body.innerHTML = '<div data-vidply-theme-sync="1"></div>';
        expect(isThemeSyncEnabled()).toBe(true);
    });
});

describe('applyTheme', () => {
    const renderPlayers = () => {
        document.body.innerHTML = `
            <div class="synced" data-vidply-theme-sync="1"><div class="vidply-player vidply-theme-dark"></div></div>
            <div class="fixed"><div class="vidply-player vidply-theme-dark"></div></div>
        `;
        const synced = { container: document.querySelector('.synced .vidply-player'), setTheme: vi.fn() };
        const fixed = { container: document.querySelector('.fixed .vidply-player'), setTheme: vi.fn() };
        trackPlayer(synced);
        trackPlayer(fixed);
        return { synced, fixed };
    };

    it('limits the automatic sync to opted-in players', () => {
        const { synced, fixed } = renderPlayers();

        applyTheme('light', { onlySynced: true, force: true });

        expect(synced.setTheme).toHaveBeenCalledWith('light');
        expect(fixed.setTheme).not.toHaveBeenCalled();
        expect(document.querySelector('.synced .vidply-player').classList.contains('vidply-theme-light')).toBe(true);
        expect(document.querySelector('.fixed .vidply-player').classList.contains('vidply-theme-dark')).toBe(true);
    });

    it('skips an automatic sync to the theme that is already applied', () => {
        const { synced } = renderPlayers();

        applyTheme('dark', { onlySynced: true, force: true });
        applyTheme('dark', { onlySynced: true });

        expect(synced.setTheme).toHaveBeenCalledOnce();
    });

    it('reaches every player on an explicit request and announces the change', () => {
        const { synced, fixed } = renderPlayers();
        const listener = vi.fn();
        document.addEventListener('vidply:themechange', listener, { once: true });

        applyTheme('anything-else');

        expect(synced.setTheme).toHaveBeenCalledWith('dark');
        expect(fixed.setTheme).toHaveBeenCalledWith('dark');
        expect(listener.mock.calls[0][0].detail).toEqual({ theme: 'dark' });
    });
});
