import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { privacyConsent } from '../../../Resources/Public/JavaScript/shared/consent.js';
import {
    createPrivacyOverlay,
    getPrivacyPolicyUrl,
    getPrivacySettings,
} from '../../../Resources/Public/JavaScript/playlist/privacy-overlay.js';

describe('getPrivacySettings', () => {
    beforeEach(() => {
        document.documentElement.lang = 'en';
    });

    it('falls back to the built-in texts without a settings record', () => {
        expect(getPrivacySettings('vimeo', null)).toEqual({
            headline: '',
            intro_text: 'To activate the video, you must click on the button. After activating the button,',
            outro_text: 'applies.',
            policy_link: 'https://vimeo.com/privacy',
            link_text: "Vimeo's privacy policy",
            button_label: 'Load and play Vimeo content',
        });
    });

    it('uses record values and fills empty fields from the built-in texts', () => {
        const settings = getPrivacySettings('youtube', {
            youtube: { headline: 'Datenschutz', intro_text: 'Custom intro', policy_link: '' },
        });

        expect(settings.headline).toBe('Datenschutz');
        expect(settings.intro_text).toBe('Custom intro');
        expect(settings.policy_link).toBe('https://policies.google.com/privacy');
        expect(settings.button_label).toBe('Load and play YouTube content');
    });

    it('knows no policy URL for unknown services', () => {
        expect(getPrivacyPolicyUrl('dailymotion')).toBe('#');
    });
});

describe('createPrivacyOverlay', () => {
    beforeEach(() => {
        document.documentElement.lang = 'en';
        privacyConsent._consent.clear();
    });

    afterEach(() => {
        document.body.replaceChildren();
        vi.useRealTimers();
    });

    it('renders CMS texts as text, never as markup', () => {
        const overlay = createPrivacyOverlay('youtube', {}, () => {}, {
            youtube: {
                headline: '<img src=x onerror=alert(1)>',
                intro_text: '<b>bold</b>',
                link_text: '<script>alert(1)</script>',
            },
        });

        expect(overlay.querySelector('img, b, script')).toBeNull();
        expect(overlay.querySelector('.vidply-privacy-headline').textContent).toBe('<img src=x onerror=alert(1)>');
        expect(overlay.querySelector('p').textContent).toContain('<b>bold</b>');
        expect(overlay.querySelector('.vidply-privacy-link').textContent).toContain('<script>alert(1)</script>');
    });

    it('replaces an unsafe policy link with the provider policy', () => {
        const overlay = createPrivacyOverlay('vimeo', {}, () => {}, {
            vimeo: { policy_link: 'javascript:alert(1)' },
        });

        const link = overlay.querySelector('.vidply-privacy-link');
        expect(link.getAttribute('href')).toBe('https://vimeo.com/privacy');
        expect(link.target).toBe('_blank');
        expect(link.rel).toBe('noopener noreferrer');
        expect(link.querySelector('.vidply-sr-only').textContent).toBe(' (opens in new window)');
    });

    it('applies only a safe poster as background image', () => {
        const safe = createPrivacyOverlay('youtube', { poster: '/fileadmin/poster.jpg' }, () => {});
        const unsafe = createPrivacyOverlay('youtube', { poster: '/x.jpg") ; background: url("evil' }, () => {});

        expect(safe.style.getPropertyValue('background-image')).toContain('/fileadmin/poster.jpg');
        expect(unsafe.style.getPropertyValue('background-image')).toBe('');
    });

    it('clamps the headline level to h2-h6', () => {
        const settings = { youtube: { headline: 'Title' } };

        expect(createPrivacyOverlay('youtube', {}, () => {}, settings, null, 'center', null, 4).querySelector('h4')).not.toBeNull();
        expect(createPrivacyOverlay('youtube', {}, () => {}, settings, null, 'center', null, 1).querySelector('h2')).not.toBeNull();
        expect(createPrivacyOverlay('youtube', {}, () => {}, settings, null, 'center', null, Number.NaN).querySelector('h2')).not.toBeNull();
    });

    it('labels the button and falls back to the built-in play icon', () => {
        const overlay = createPrivacyOverlay('soundcloud', {}, () => {}, null, 'javascript:alert(1)', 'bottom-left');
        const button = overlay.querySelector('button');

        expect(button.getAttribute('aria-label')).toBe('Load and play SoundCloud content');
        expect(button.classList.contains('vidply-privacy-button--pos-bottom-left')).toBe(true);
        expect(button.querySelector('img')).toBeNull();
        expect(button.querySelector('svg.vidply-play-overlay')).not.toBeNull();
    });

    it('uses a safe play icon URL as image', () => {
        const overlay = createPrivacyOverlay('youtube', {}, () => {}, null, '/fileadmin/play.svg');
        const img = overlay.querySelector('button img');

        expect(img.getAttribute('src')).toBe('/fileadmin/play.svg');
        expect(img.alt).toBe('');
    });

    it('stores consent, removes itself and continues on click', () => {
        const onConsent = vi.fn();
        const overlay = createPrivacyOverlay('youtube', {}, onConsent);
        document.body.appendChild(overlay);

        overlay.querySelector('button').click();

        expect(privacyConsent.hasConsent('youtube')).toBe(true);
        expect(onConsent).toHaveBeenCalledOnce();
        expect(overlay.isConnected).toBe(false);
    });
});
