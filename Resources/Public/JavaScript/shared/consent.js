/**
 * Consent state for the external media services, shared between the privacy
 * layer and the playlist player.
 *
 * Both entry points can be on the page in either order, so the state lives on
 * `window` and the first module to load creates it.
 */

import { KNOWN_SERVICES } from './media-services.js';

const createConsentStore = () => ({
    _consent: new Set(),

    hasConsent(service) {
        return KNOWN_SERVICES.has(service) && this._consent.has(service);
    },

    setConsent(service) {
        if (!KNOWN_SERVICES.has(service)) {
            return;
        }
        this._consent.add(service);
        document.dispatchEvent(new CustomEvent('vidply:privacy:consent', { detail: { service } }));
    },
});

window.VidPlyPrivacyConsent = window.VidPlyPrivacyConsent || createConsentStore();

export const privacyConsent = window.VidPlyPrivacyConsent;
