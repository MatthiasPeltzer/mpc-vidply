/**
 * VidPly Initialization for TYPO3
 * Handles both single players and playlists, including mixed media playlists
 * with privacy consent for external services (YouTube, Vimeo, SoundCloud)
 */

import {Player, PlaylistManager} from './vidply/vidply.esm.min.js';

// Track initialized players to prevent double-init
const initializedPlayers = new WeakSet();

// Suppress VidPly's internal console logs
const originalConsoleLog = console.log;
const suppressVidPlyLogs = (fn) => {
    console.log = (...args) => {
        // Only suppress VidPly Playlist logs
        if (args[0]?.toString().startsWith('VidPly')) return;
        originalConsoleLog.apply(console, args);
    };
    try {
        fn();
    } finally {
        console.log = originalConsoleLog;
    }
};

// Initialize shared privacy consent state (if not already initialized by PrivacyLayer.js)
window.VidPlyPrivacyConsent = window.VidPlyPrivacyConsent || {
    youtube: false,
    vimeo: false,
    soundcloud: false,
    hasConsent(service) {
        return !!this[service];
    },
    setConsent(service) {
        this[service] = true;
        document.dispatchEvent(new CustomEvent('vidply:privacy:consent', {detail: {service}}));
    }
};

const privacyConsent = window.VidPlyPrivacyConsent;

// URL detection utilities
const isExternalRendererUrl = (src) => {
    if (!src) return false;
    const s = src.toLowerCase();
    return s.includes('youtube.com') || s.includes('youtu.be') ||
        s.includes('vimeo.com') || s.includes('soundcloud.com') || s.includes('.m3u8');
};

const getServiceType = (src) => {
    if (!src) return null;
    const s = src.toLowerCase();
    if (s.includes('youtube.com') || s.includes('youtu.be')) return 'youtube';
    if (s.includes('vimeo.com')) return 'vimeo';
    if (s.includes('soundcloud.com')) return 'soundcloud';
    return null;
};

const getPrivacyPolicyUrl = (service) => ({
    youtube: 'https://policies.google.com/privacy',
    vimeo: 'https://vimeo.com/privacy',
    soundcloud: 'https://soundcloud.com/pages/privacy'
}[service] || '#');

// Language utilities
const getPageLanguage = () => (document.documentElement.lang || 'en').split('-')[0].toLowerCase();

// Translations
const translations = {
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
        loadDefault: 'Load and play content'
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
        loadDefault: 'Inhalt laden und abspielen'
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
        loadDefault: 'Cargar y reproducir contenido'
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
        loadDefault: 'Carica e riproduci contenuto'
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
        loadDefault: 'コンテンツを読み込んで再生'
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
        loadDefault: 'Charger et lire le contenu'
    }
};

const getTranslation = (key) => {
    const lang = getPageLanguage();
    return (translations[lang] || translations.en)[key] || translations.en[key];
};

const getPrivacyIntroText = (service) => getTranslation(service === 'soundcloud' ? 'widgetIntro' : 'videoIntro');

const getPrivacyPolicyLinkText = (service) => getTranslation({
    youtube: 'googlePolicy',
    vimeo: 'vimeoPolicy',
    soundcloud: 'soundcloudPolicy'
}[service] || 'googlePolicy');

const getButtonAriaLabel = (service) => getTranslation({
    youtube: 'loadYoutube',
    vimeo: 'loadVimeo',
    soundcloud: 'loadSoundcloud'
}[service] || 'loadDefault');

/**
 * Create the privacy consent overlay for a playlist track
 */
function createPrivacyOverlay(service, track, onConsent) {
    const overlay = document.createElement('div');
    overlay.className = `vidply-playlist-privacy-overlay vidply-privacy-layer vidply-privacy-${service}`;
    overlay.setAttribute('data-privacy-service', service);

    const bgStyle = track.poster
        ? `background-color: #1a1a1a; background-image: url('${track.poster}'); background-size: cover; background-position: center;`
        : 'background-color: #1a1a1a;';

    overlay.style.cssText = `
        position: relative !important; padding-bottom: 56.25% !important; height: 0 !important;
        width: 100% !important; z-index: 9999 !important; overflow: visible !important;
        pointer-events: auto !important; ${bgStyle}
    `;

    const innerContainer = document.createElement('div');
    innerContainer.style.cssText = `
        position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
    `;

    const playButton = document.createElement('button');
    playButton.className = 'vidply-privacy-button';
    playButton.type = 'button';
    playButton.setAttribute('aria-label', getButtonAriaLabel(service));
    playButton.style.cssText = `
        background: transparent; border: none; cursor: pointer; padding: 0;
        transition: transform 0.3s ease; position: absolute; top: 50%; left: 50%;
        transform: translate(-50%, -50%); z-index: 10;
    `;

    const filterId = `vidply-play-shadow-privacy-${Date.now()}`;
    playButton.innerHTML = `
        <svg class="vidply-play-overlay" viewBox="0 0 80 80" width="80" height="80" aria-hidden="true" style="cursor: pointer;">
            <defs>
                <filter id="${filterId}" x="-50%" y="-50%" width="200%" height="200%">
                    <feGaussianBlur in="SourceAlpha" stdDeviation="3"></feGaussianBlur>
                    <feOffset dx="0" dy="2" result="offsetblur"></feOffset>
                    <feComponentTransfer><feFuncA type="linear" slope="0.3"></feFuncA></feComponentTransfer>
                    <feMerge><feMergeNode></feMergeNode><feMergeNode in="SourceGraphic"></feMergeNode></feMerge>
                </filter>
            </defs>
            <circle cx="40" cy="40" r="40" fill="rgba(255, 255, 255, 0.95)" filter="url(#${filterId})"></circle>
            <polygon points="32,28 32,52 54,40" fill="currentColor" class="vidply-play-overlay-icon"></polygon>
        </svg>
    `;

    playButton.addEventListener('mouseenter', () => playButton.style.transform = 'translate(-50%, -50%) scale(1.1)');
    playButton.addEventListener('mouseleave', () => playButton.style.transform = 'translate(-50%, -50%) scale(1)');

    innerContainer.appendChild(playButton);

    const privacyText = document.createElement('div');
    privacyText.className = 'vidply-privacy-text';
    privacyText.style.cssText = `
        position: absolute; bottom: 0; left: 0; right: 0; color: #fff;
        background: rgba(0, 0, 0, 0.8); padding: 1rem; text-align: center; z-index: 9;
    `;
    privacyText.innerHTML = `<p style="margin: 0; font-size: 0.85rem;">
        ${getPrivacyIntroText(service)} 
        <a href="${getPrivacyPolicyUrl(service)}" target="_blank" rel="noopener noreferrer" style="color: #fff; text-decoration: underline;">
            ${getPrivacyPolicyLinkText(service)}
        </a> ${getTranslation('applies')}
    </p>`;

    innerContainer.appendChild(privacyText);
    overlay.appendChild(innerContainer);

    playButton.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        privacyConsent.setConsent(service);
        overlay.remove();
        onConsent();
    });

    return overlay;
}

/**
 * Initialize a single media element
 */
function initializeSingleElement(element) {
    if (initializedPlayers.has(element)) return;

    try {
        const externalSrc = element.dataset.vidplySrc;
        const isHLS = externalSrc?.includes('.m3u8');

        if (isHLS) {
            const source = document.createElement('source');
            source.src = externalSrc;
            source.type = 'application/x-mpegURL';
            element.appendChild(source);
        } else if (externalSrc) {
            element.src = externalSrc;
        }

        const options = element.dataset.vidplyOptions ? JSON.parse(element.dataset.vidplyOptions) : {};
        let player;
        suppressVidPlyLogs(() => {
            player = new Player(element, options);
        });

        if (isHLS) {
            player.on('ready', () => {
                const checkQuality = (attempts = 0) => {
                    if (attempts > 10) return;
                    if (player.renderer?.hls?.levels?.length > 0) {
                        player.controls?.buildControlBar?.();
                        window.dispatchEvent(new Event('resize'));
                    } else {
                        setTimeout(() => checkQuality(attempts + 1), 500);
                    }
                };
                setTimeout(checkQuality, 500);
            });
        }

        initializedPlayers.add(element);
        element._vidplyPlayer = player;
    } catch (error) {
        console.error('[VidPly Init] Error:', error);
    }
}

/**
 * Initialize a playlist element with privacy consent handling
 */
function initializePlaylistElement(element) {
    if (!element.id || initializedPlayers.has(element)) return;

    try {
        const tracks = element.dataset.playlist ? JSON.parse(element.dataset.playlist) : [];
        if (!tracks.length) return;

        const options = element.dataset.vidplyOptions ? JSON.parse(element.dataset.vidplyOptions) : {};
        const hasExternalMedia = element.dataset.playlistHasExternal === 'true';
        const wrapperElement = element.closest('.vidply-wrapper') || element.parentElement;
        const mediaType = element.tagName === 'DIV'
            ? (element.dataset.vidplyMediaType || 'video')
            : (element.tagName === 'AUDIO' ? 'audio' : 'video');

        // Remove data-playlist before creating player for external media playlists
        if (hasExternalMedia) {
            element.removeAttribute('data-playlist');
        }

        let player;
        suppressVidPlyLogs(() => {
            player = new Player(element, {...options, mediaType});
        });

        player.on('ready', () => {
            const autoPlayFirst = element.dataset.playlistAutoPlayFirst === 'true';

            const playlistOptions = {
                autoAdvance: element.dataset.playlistAutoAdvance !== 'false',
                autoPlayFirst: hasExternalMedia ? false : autoPlayFirst,
                loop: element.dataset.playlistLoop === 'true',
                showPanel: element.dataset.playlistShowPanel !== 'false',
                tracks: hasExternalMedia ? [] : tracks,
                recreatePlayers: hasExternalMedia && element.tagName === 'DIV',
                hostElement: element.tagName === 'DIV' ? element : null,
                PlayerClass: Player
            };

            let playlist;
            suppressVidPlyLogs(() => {
                playlist = new PlaylistManager(player, playlistOptions);
            });
            element._vidplyPlaylist = playlist;

            // Patch error handler
            player.off('error', playlist.handleTrackError);
            playlist.handleTrackError = function (e) {
                const currentTrack = this.getCurrentTrack();
                if (currentTrack?.src && isExternalRendererUrl(currentTrack.src)) return;
                if (playlistOptions.autoAdvance) setTimeout(() => this.next(), 1000);
            }.bind(playlist);
            player.on('error', playlist.handleTrackError);

            // Setup privacy interception for external media
            if (hasExternalMedia) {
                setupPrivacyInterception(playlist, element, wrapperElement, tracks, autoPlayFirst);
            }
        });

        initializedPlayers.add(element);
        element._vidplyPlayer = player;
    } catch (error) {
        console.error('[VidPly Init] Playlist error:', error);
    }
}

/**
 * Setup privacy consent interception for playlists with external media
 */
function setupPrivacyInterception(playlist, element, wrapperElement, tracks, autoPlayFirst) {
    const originalLoadTrack = playlist.loadTrack.bind(playlist);
    const originalPlay = playlist.play.bind(playlist);

    // Helper: Remove overlay and restore hidden elements
    const removeExistingOverlay = () => {
        element.querySelector('.vidply-playlist-privacy-overlay')?.remove();

        const currentPlayer = playlist.player;
        if (currentPlayer?.videoWrapper) {
            currentPlayer.videoWrapper.style.display = '';
            currentPlayer.videoWrapper.removeAttribute('data-vidply-hidden');
        }

        element.querySelectorAll('[data-vidply-hidden]').forEach(el => {
            el.style.display = '';
            el.removeAttribute('data-vidply-hidden');
        });
    };

    // Helper: Ensure autoplay after external content loads
    const ensureAutoplay = (serviceType) => {
        setTimeout(() => {
            const currentPlayer = playlist.player;
            if (!currentPlayer) return;

            const tryPlay = () => {
                try {
                    if (currentPlayer.play) {
                        currentPlayer.play();
                    }
                } catch (e) {
                    // Autoplay might be blocked by browser
                }
            };

            // For external services, wait for ready event or use longer timeout
            if (typeof currentPlayer.on === 'function') {
                currentPlayer.on('ready', () => setTimeout(tryPlay, 100));
            }

            // Also try after delays to handle different loading times
            setTimeout(tryPlay, 500);
            setTimeout(tryPlay, 1000);
            setTimeout(tryPlay, 2000); // SoundCloud can be slow
        }, 300);
    };

    // Helper: Show consent overlay then proceed
    const showConsentThenProceed = (serviceType, track, index, proceedFn) => {
        const currentPlayer = playlist.player;

        if (currentPlayer) {
            try {
                currentPlayer.pause();
                if (currentPlayer.videoWrapper) {
                    currentPlayer.videoWrapper.style.display = 'none';
                    currentPlayer.videoWrapper.setAttribute('data-vidply-hidden', 'true');
                }
                element.querySelector('.vidply-track-artwork')?.setAttribute('data-vidply-hidden', 'true');
                element.querySelector('.vidply-track-artwork')?.style.setProperty('display', 'none');
            } catch (e) { /* ignore */
            }
        }

        // Remove existing overlays
        [element, element.parentElement, wrapperElement].forEach(target => {
            target?.querySelector('.vidply-playlist-privacy-overlay')?.remove();
        });

        // Update playlist UI
        playlist.currentIndex = index;
        playlist.updatePlaylistUI?.();
        playlist.updateTrackInfo?.(track);

        // Create and insert overlay
        const overlay = createPrivacyOverlay(serviceType, track, () => {
            // Restore visibility
            const player = playlist.player;
            if (player?.videoWrapper) {
                player.videoWrapper.style.display = '';
                player.videoWrapper.removeAttribute('data-vidply-hidden');
            }
            element.querySelectorAll('[data-vidply-hidden]').forEach(el => {
                el.style.display = '';
                el.removeAttribute('data-vidply-hidden');
            });

            // Proceed with original method
            proceedFn();

            // Autoplay after consent
            ensureAutoplay(serviceType);
        });

        // Find insertion point
        let insertBefore = null;
        for (const child of element.children) {
            if (child.classList.contains('vidply-video-wrapper') || child.classList.contains('vidply-player')) {
                insertBefore = child;
                break;
            }
        }

        if (insertBefore) {
            element.insertBefore(overlay, insertBefore);
        } else if (element.firstChild) {
            element.insertBefore(overlay, element.firstChild);
        } else {
            element.appendChild(overlay);
        }
    };

    // Shared interceptor logic
    const interceptTrackMethod = (index, userInitiated, originalFn) => {
        const track = playlist.tracks[index];
        if (!track) return originalFn(index, userInitiated);

        const serviceType = getServiceType(track.src);

        if (serviceType && !privacyConsent.hasConsent(serviceType)) {
            showConsentThenProceed(serviceType, track, index, () => originalFn(index, userInitiated));
            return;
        }

        removeExistingOverlay();
        const result = originalFn(index, userInitiated);

        // For external services with consent, also ensure autoplay
        if (serviceType) {
            ensureAutoplay(serviceType);
        }

        return result;
    };

    // Patch methods with shared logic
    playlist.loadTrack = function (index) {
        return interceptTrackMethod(index, undefined, (idx) => originalLoadTrack(idx));
    };

    playlist.play = function (index, userInitiated) {
        return interceptTrackMethod(index, userInitiated, (idx, ui) => originalPlay(idx, ui));
    };

    // Load playlist with patched methods
    playlist.options.autoPlayFirst = autoPlayFirst;
    suppressVidPlyLogs(() => {
        playlist.loadPlaylist(tracks);
    });
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    // Initialize single media elements
    document.querySelectorAll('[data-vidply-init]:not([data-playlist])').forEach(initializeSingleElement);

    // Initialize playlist elements
    document.querySelectorAll('[data-playlist]:not([data-vidply])').forEach(initializePlaylistElement);
});
