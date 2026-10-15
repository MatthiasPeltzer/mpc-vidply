/**
 * Builds the privacy consent overlay shown in front of an external playlist
 * track (YouTube, Vimeo, SoundCloud). All CMS-provided texts are inserted as
 * text nodes, never as HTML.
 */

import {privacyConsent} from '../shared/consent.js';
import {isSafeUrl, sanitizeCssUrl} from '../shared/url-safety.js';
import {OVERLAY_INSERT_DELAY} from './constants.js';
import {
    getButtonAriaLabel,
    getPrivacyIntroText,
    getPrivacyPolicyLinkText,
    getTranslation
} from './i18n.js';
import {parseSvgFromMarkup} from './play-icon.js';

const PRIVACY_POLICY_URLS = {
    youtube: 'https://policies.google.com/privacy',
    vimeo: 'https://vimeo.com/privacy',
    soundcloud: 'https://soundcloud.com/pages/privacy'
};

// Counter for unique SVG filter IDs
let filterIdCounter = 0;

export const getPrivacyPolicyUrl = (service) => PRIVACY_POLICY_URLS[service] || '#';

/**
 * Get privacy settings for a service, with fallback to translations
 */
export function getPrivacySettings(service, privacySettings) {
    const settings = privacySettings?.[service];
    if (!settings) {
        // Fallback to hardcoded translations
        return {
            headline: '',
            intro_text: getPrivacyIntroText(service),
            outro_text: getTranslation('applies'),
            policy_link: getPrivacyPolicyUrl(service),
            link_text: getPrivacyPolicyLinkText(service),
            button_label: getButtonAriaLabel(service)
        };
    }

    // Use database settings, with fallback to translations if empty
    return {
        headline: settings.headline || '',
        intro_text: settings.intro_text || getPrivacyIntroText(service),
        outro_text: settings.outro_text || getTranslation('applies'),
        policy_link: settings.policy_link || getPrivacyPolicyUrl(service),
        link_text: settings.link_text || getPrivacyPolicyLinkText(service),
        button_label: settings.button_label || getButtonAriaLabel(service)
    };
}

/**
 * Create the privacy consent overlay for a playlist track
 */
export function createPrivacyOverlay(service, track, onConsent, privacySettings = null, playIconUrl = null, playButtonPosition = 'center', playIconInlineSvg = null, headlineLevel = 2) {
    const settings = getPrivacySettings(service, privacySettings);

    const overlay = document.createElement('div');
    overlay.className = `vidply-playlist-privacy-overlay vidply-privacy-layer vidply-privacy-${service}`;
    overlay.setAttribute('data-privacy-service', service);

    const safePoster = sanitizeCssUrl(track.poster);
    if (safePoster) {
        overlay.style.setProperty('background-image', `url("${safePoster}")`);
        overlay.style.setProperty('background-size', 'cover');
        overlay.style.setProperty('background-position', 'center');
    }

    const innerContainer = document.createElement('div');
    innerContainer.className = 'vidply-privacy-inner-container';

    const playButton = document.createElement('button');
    const position = playButtonPosition || 'center';
    playButton.className = `vidply-privacy-button vidply-privacy-button--pos-${position}`;
    playButton.type = 'button';
    playButton.setAttribute('aria-label', settings.button_label);

    if (playIconInlineSvg) {
        const parsed = parseSvgFromMarkup(playIconInlineSvg);
        if (parsed) {
            playButton.appendChild(parsed);
        }
    }

    if (!playButton.firstElementChild && isSafeUrl(playIconUrl)) {
        const img = document.createElement('img');
        img.className = 'vidply-play-overlay-image';
        img.src = playIconUrl;
        img.alt = '';
        img.setAttribute('aria-hidden', 'true');
        playButton.appendChild(img);
    } else if (!playButton.firstElementChild) {
        const filterId = `vidply-play-shadow-privacy-${++filterIdCounter}`;
        playButton.innerHTML = `
            <svg class="vidply-play-overlay" viewBox="0 0 80 80" width="80" height="80" aria-hidden="true" role="presentation">
                <defs>
                    <filter id="${filterId}" x="-50%" y="-50%" width="200%" height="200%">
                        <feGaussianBlur in="SourceAlpha" stdDeviation="3"></feGaussianBlur>
                        <feOffset dx="0" dy="2" result="offsetblur"></feOffset>
                        <feComponentTransfer><feFuncA type="linear" slope="0.3"></feFuncA></feComponentTransfer>
                        <feMerge><feMergeNode></feMergeNode><feMergeNode in="SourceGraphic"></feMergeNode></feMerge>
                    </filter>
                </defs>
                <circle cx="40" cy="40" r="40" fill="rgba(255, 255, 255, 0.95)" filter="url(#${filterId})" class="vidply-play-overlay-bg"></circle>
                <polygon points="32,28 32,52 54,40" fill="#0a406e" class="vidply-play-overlay-icon"></polygon>
            </svg>
        `;
    }

    innerContainer.appendChild(playButton);

    const privacyText = document.createElement('div');
    privacyText.className = 'vidply-privacy-text';

    if (settings.headline) {
        // One level below the content element's header, as in PrivacyLayer.html.
        const level = Number.isInteger(headlineLevel) && headlineLevel >= 2 && headlineLevel <= 6 ? headlineLevel : 2;
        const headlineEl = document.createElement(`h${level}`);
        headlineEl.className = 'vidply-privacy-headline';
        headlineEl.textContent = settings.headline;
        privacyText.appendChild(headlineEl);
    }

    const p = document.createElement('p');
    p.appendChild(document.createTextNode(settings.intro_text + ' '));
    const policyLink = document.createElement('a');
    policyLink.href = isSafeUrl(settings.policy_link) ? settings.policy_link : getPrivacyPolicyUrl(service);
    policyLink.target = '_blank';
    policyLink.rel = 'noopener noreferrer';
    policyLink.className = 'vidply-privacy-link';
    policyLink.textContent = settings.link_text;
    const srHint = document.createElement('span');
    srHint.className = 'vidply-sr-only';
    srHint.textContent = getTranslation('opensNewWindow');
    policyLink.appendChild(srHint);
    p.appendChild(policyLink);
    p.appendChild(document.createTextNode(' ' + settings.outro_text));
    privacyText.appendChild(p);

    innerContainer.appendChild(privacyText);
    overlay.appendChild(innerContainer);

    playButton.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        privacyConsent.setConsent(service);
        const container = overlay.parentElement;
        overlay.remove();
        onConsent();
        const focusTarget = container?.querySelector('video, audio, button, [tabindex]');
        if (focusTarget) setTimeout(() => focusTarget.focus({ preventScroll: true }), OVERLAY_INSERT_DELAY);
    });

    return overlay;
}
