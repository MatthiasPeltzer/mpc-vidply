import { describe, expect, it } from 'vitest';
import { hideControlsForExternalEmbed } from '../../../Resources/Public/JavaScript/playlist/overlay-dom.js';

describe('hideControlsForExternalEmbed', () => {
    it('adds the external-controls class to the player container', () => {
        const container = document.createElement('div');
        container.className = 'vidply-player';

        hideControlsForExternalEmbed({ player: { container } });

        expect(container.classList.contains('vidply-external-controls')).toBe(true);
    });

    it.each([[null], [{}], [{ player: null }], [{ player: {} }]])('ignores a playlist without container: %j', (playlist) => {
        expect(() => hideControlsForExternalEmbed(playlist)).not.toThrow();
    });
});
