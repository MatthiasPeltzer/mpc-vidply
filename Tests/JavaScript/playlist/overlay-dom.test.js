import { describe, expect, it } from 'vitest';
import { hideControlsForExternalEmbed } from '../../../Resources/Public/JavaScript/playlist/overlay-dom.js';

describe('hideControlsForExternalEmbed', () => {
    it('adds external-controls when embedChrome is provider', () => {
        const container = document.createElement('div');
        container.className = 'vidply-player';

        hideControlsForExternalEmbed({
            player: {
                container,
                options: { embedChrome: 'provider' },
            },
        });

        expect(container.classList.contains('vidply-external-controls')).toBe(true);
    });

    it('syncs embed chrome when embedChrome is vidply (default)', () => {
        const container = document.createElement('div');
        container.className = 'vidply-player vidply-external-controls';

        hideControlsForExternalEmbed({
            player: {
                container,
                options: { embedChrome: 'vidply' },
                syncEmbedChromeClass: () => {
                    container.classList.remove('vidply-external-controls');
                    container.classList.add('vidply-embed-chrome-vidply');
                },
            },
        });

        expect(container.classList.contains('vidply-external-controls')).toBe(false);
        expect(container.classList.contains('vidply-embed-chrome-vidply')).toBe(true);
    });

    it.each([[null], [{}], [{ player: null }], [{ player: {} }]])('ignores a playlist without container: %j', (playlist) => {
        expect(() => hideControlsForExternalEmbed(playlist)).not.toThrow();
    });
});
