import { afterEach, describe, expect, it } from 'vitest';
import {
    TRANSLATIONS,
    getButtonAriaLabel,
    getPageLanguage,
    getPrivacyIntroText,
    getPrivacyPolicyLinkText,
    getTranslation,
} from '../../../Resources/Public/JavaScript/playlist/i18n.js';

const setLang = (lang) => {
    if (lang === null) {
        document.documentElement.removeAttribute('lang');
    } else {
        document.documentElement.lang = lang;
    }
};

describe('playlist i18n', () => {
    afterEach(() => setLang(null));

    it('reads the primary language subtag of <html lang>', () => {
        setLang('de-DE');
        expect(getPageLanguage()).toBe('de');

        setLang('FR');
        expect(getPageLanguage()).toBe('fr');
    });

    it('falls back to English without a lang attribute', () => {
        setLang(null);
        expect(getPageLanguage()).toBe('en');
        expect(getTranslation('applies')).toBe('applies.');
    });

    it('falls back to English for unsupported languages', () => {
        setLang('nl');
        expect(getTranslation('loadDefault')).toBe(TRANSLATIONS.en.loadDefault);
    });

    it('defines every English key in each translation', () => {
        const keys = Object.keys(TRANSLATIONS.en).sort();
        for (const [lang, strings] of Object.entries(TRANSLATIONS)) {
            expect(Object.keys(strings).sort(), lang).toEqual(keys);
        }
    });

    it('maps services to their texts', () => {
        setLang('de');
        expect(getPrivacyIntroText('soundcloud')).toBe(TRANSLATIONS.de.widgetIntro);
        expect(getPrivacyIntroText('youtube')).toBe(TRANSLATIONS.de.videoIntro);
        expect(getPrivacyPolicyLinkText('vimeo')).toBe('die Datenschutzerklärung von Vimeo');
        expect(getButtonAriaLabel('youtube')).toBe('YouTube-Inhalt laden und abspielen');
    });

    it('uses generic texts for unknown services', () => {
        setLang('en');
        expect(getPrivacyIntroText('dailymotion')).toBe(TRANSLATIONS.en.videoIntro);
        expect(getPrivacyPolicyLinkText('dailymotion')).toBe(TRANSLATIONS.en.googlePolicy);
        expect(getButtonAriaLabel('dailymotion')).toBe(TRANSLATIONS.en.loadDefault);
    });
});
