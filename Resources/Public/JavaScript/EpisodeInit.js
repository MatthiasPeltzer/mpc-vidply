/**
 * VidPly episode card: wires the cover play button to the card's player.
 * No external dependencies. ESM module.
 */

const PLAY_BUTTON_SELECTOR = '[data-mpc-episode-play]';
const CARD_SELECTOR = '.mpc-episode';

const wired = new WeakSet();

/**
 * Resolve the player lazily, at click time: PlaylistInit assigns
 * `_vidplyPlayer` during its own DOMContentLoaded pass, so the reference may
 * not exist yet when this module runs.
 */
const findPlayer = (card) => {
    const element = card?.querySelector('[data-vidply-init], [data-playlist]');
    return element?._vidplyPlayer ?? null;
};

const setState = (button, playing) => {
    const label = playing
        ? button.dataset.mpcEpisodeLabelPause
        : button.dataset.mpcEpisodeLabelPlay;
    const title = button.dataset.mpcEpisodeTitle;

    button.dataset.state = playing ? 'playing' : 'paused';
    if (label) {
        button.setAttribute('aria-label', title ? `${label}: ${title}` : label);
    }
};

const subscribe = (button, player) => {
    if (wired.has(button) || typeof player.on !== 'function') {
        return;
    }
    wired.add(button);

    player.on('play', () => setState(button, true));
    player.on('pause', () => setState(button, false));
    player.on('ended', () => setState(button, false));
};

const handleClick = (button) => {
    const card = button.closest(CARD_SELECTOR);
    const player = findPlayer(card);
    if (!player) {
        return;
    }

    subscribe(button, player);

    const isPlaying = typeof player.isPlaying === 'function'
        ? player.isPlaying()
        : button.dataset.state === 'playing';

    if (isPlaying) {
        player.pause?.();
    } else {
        player.play?.();
    }
};

const initializeButton = (button) => {
    if (button.dataset.mpcEpisodeReady === 'true') {
        return;
    }
    button.dataset.mpcEpisodeReady = 'true';
    button.dataset.state = 'paused';

    button.addEventListener('click', () => handleClick(button));
};

/**
 * @param {ParentNode} [root=document]
 */
const scan = (root = document) => {
    const scope = root instanceof Element || root instanceof Document ? root : document;
    scope.querySelectorAll(PLAY_BUTTON_SELECTOR).forEach(initializeButton);
};

document.addEventListener('mpc:dynamic-content:ready', (event) => {
    const root = event.detail?.root;
    scan(root instanceof Element ? root : document);
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => scan());
} else {
    scan();
}

window.MpcVidPlyEpisode = { scan };
