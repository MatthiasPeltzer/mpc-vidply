/**
 * Fallback texts of the playlist privacy overlay, used when no privacy settings
 * record provides them. The language follows `<html lang>`.
 */

// Language utilities
export const getPageLanguage = () => (document.documentElement.lang || 'en').split('-')[0].toLowerCase();

// Translations
export const TRANSLATIONS = {
    en: {
        videoIntro: 'To activate the video, you must click on the button. After activating the button,',
        widgetIntro: 'To activate the widget, you must click on the button. After activating the button,',
        applies: 'applies.',
        googlePolicy: "Google's privacy policy",
        vimeoPolicy: "Vimeo's privacy policy",
        soundcloudPolicy: "SoundCloud's privacy policy",
        loadYoutube: 'Load and play YouTube content',
        loadVimeo: 'Load and play Vimeo content',
        loadSoundcloud: 'Load and play SoundCloud content',
        loadDefault: 'Load and play content',
        opensNewWindow: ' (opens in new window)'
    },
    de: {
        videoIntro: 'Um das Video zu aktivieren, müssen Sie auf die Schaltfläche klicken. Nach der Aktivierung gilt',
        widgetIntro: 'Um das Widget zu aktivieren, müssen Sie auf die Schaltfläche klicken. Nach der Aktivierung gilt',
        applies: '.',
        googlePolicy: 'die Datenschutzerklärung von Google',
        vimeoPolicy: 'die Datenschutzerklärung von Vimeo',
        soundcloudPolicy: 'die Datenschutzerklärung von SoundCloud',
        loadYoutube: 'YouTube-Inhalt laden und abspielen',
        loadVimeo: 'Vimeo-Inhalt laden und abspielen',
        loadSoundcloud: 'SoundCloud-Inhalt laden und abspielen',
        loadDefault: 'Inhalt laden und abspielen',
        opensNewWindow: ' (öffnet in neuem Fenster)'
    },
    es: {
        videoIntro: 'Para activar el vídeo, debe hacer clic en el botón. Después de activar el botón,',
        widgetIntro: 'Para activar el widget, debe hacer clic en el botón. Después de activar el botón,',
        applies: 'se aplica.',
        googlePolicy: 'la política de privacidad de Google',
        vimeoPolicy: 'la política de privacidad de Vimeo',
        soundcloudPolicy: 'la política de privacidad de SoundCloud',
        loadYoutube: 'Cargar y reproducir contenido de YouTube',
        loadVimeo: 'Cargar y reproducir contenido de Vimeo',
        loadSoundcloud: 'Cargar y reproducir contenido de SoundCloud',
        loadDefault: 'Cargar y reproducir contenido',
        opensNewWindow: ' (se abre en una ventana nueva)'
    },
    it: {
        videoIntro: 'Per attivare il video, è necessario fare clic sul pulsante. Dopo aver attivato il pulsante,',
        widgetIntro: 'Per attivare il widget, è necessario fare clic sul pulsante. Dopo aver attivato il pulsante,',
        applies: 'si applica.',
        googlePolicy: "l'informativa sulla privacy di Google",
        vimeoPolicy: "l'informativa sulla privacy di Vimeo",
        soundcloudPolicy: "l'informativa sulla privacy di SoundCloud",
        loadYoutube: 'Carica e riproduci contenuto YouTube',
        loadVimeo: 'Carica e riproduci contenuto Vimeo',
        loadSoundcloud: 'Carica e riproduci contenuto SoundCloud',
        loadDefault: 'Carica e riproduci contenuto',
        opensNewWindow: ' (si apre in una nuova finestra)'
    },
    ja: {
        videoIntro: '動画を有効にするには、ボタンをクリックしてください。ボタンを有効にすると、',
        widgetIntro: 'ウィジェットを有効にするには、ボタンをクリックしてください。ボタンを有効にすると、',
        applies: 'が適用されます。',
        googlePolicy: 'Googleのプライバシーポリシー',
        vimeoPolicy: 'Vimeoのプライバシーポリシー',
        soundcloudPolicy: 'SoundCloudのプライバシーポリシー',
        loadYoutube: 'YouTubeコンテンツを読み込んで再生',
        loadVimeo: 'Vimeoコンテンツを読み込んで再生',
        loadSoundcloud: 'SoundCloudコンテンツを読み込んで再生',
        loadDefault: 'コンテンツを読み込んで再生',
        opensNewWindow: '（新しいウィンドウで開きます）'
    },
    fr: {
        videoIntro: 'Pour activer la vidéo, vous devez cliquer sur le bouton. Après avoir activé le bouton,',
        widgetIntro: 'Pour activer le widget, vous devez cliquer sur le bouton. Après avoir activé le bouton,',
        applies: "s'applique.",
        googlePolicy: 'la politique de confidentialité de Google',
        vimeoPolicy: 'la politique de confidentialité de Vimeo',
        soundcloudPolicy: 'la politique de confidentialité de SoundCloud',
        loadYoutube: 'Charger et lire le contenu YouTube',
        loadVimeo: 'Charger et lire le contenu Vimeo',
        loadSoundcloud: 'Charger et lire le contenu SoundCloud',
        loadDefault: 'Charger et lire le contenu',
        opensNewWindow: ' (s\u2019ouvre dans une nouvelle fen\u00eatre)'
    }
};

const TRANSLATION_KEY_MAP = {
    introText: {youtube: 'videoIntro', vimeo: 'videoIntro', soundcloud: 'widgetIntro'},
    policyText: {youtube: 'googlePolicy', vimeo: 'vimeoPolicy', soundcloud: 'soundcloudPolicy'},
    buttonLabel: {youtube: 'loadYoutube', vimeo: 'loadVimeo', soundcloud: 'loadSoundcloud'}
};

export const getTranslation = (key) => {
    const lang = getPageLanguage();
    return (TRANSLATIONS[lang] || TRANSLATIONS.en)[key] || TRANSLATIONS.en[key];
};

export const getPrivacyIntroText = (service) => getTranslation(TRANSLATION_KEY_MAP.introText[service] || 'videoIntro');
export const getPrivacyPolicyLinkText = (service) => getTranslation(TRANSLATION_KEY_MAP.policyText[service] || 'googlePolicy');
export const getButtonAriaLabel = (service) => getTranslation(TRANSLATION_KEY_MAP.buttonLabel[service] || 'loadDefault');
