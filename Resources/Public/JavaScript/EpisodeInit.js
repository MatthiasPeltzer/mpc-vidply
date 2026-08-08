/**
 * VidPly episode layouts: wires the episode play buttons to the layout's player.
 *
 * The `episodes` layout renders one button per playlist track and suppresses the
 * player's own panel, so a click has to select the matching track rather than
 * just toggle playback. It also mirrors the selected track into the card above
 * the player. No external dependencies. ESM module.
 */

const PLAY_BUTTON_SELECTOR = '[data-mpc-episode-play]';
const ROOT_SELECTOR = '.mpc-episode';
const ITEM_SELECTOR = '[data-mpc-episode-item]';
const PLAYER_SELECTOR = '[data-vidply-init], [data-playlist]';
/* The card above the player, as opposed to the identically built list rows. */
const CURRENT_COVER_SELECTOR = ':scope > .mpc-episode-cover';
const CURRENT_HEADER_SELECTOR = ':scope > .mpc-episode-main > .mpc-episode-header';
const TITLE_SELECTOR = '.mpc-episode-title';

/**
 * PlaylistInit assigns `_vidplyPlayer` during its own DOMContentLoaded pass, so
 * the player may not exist yet. Retry a few times to pick up playback the user
 * did not start from a card (auto-advance, the player's next/previous buttons).
 */
const ATTACH_DELAYS = [100, 300, 800, 2000];

const wiredPlayers = new WeakSet();
const wiredRoots = new WeakSet();

const findPlayer = (root) => root?.querySelector(PLAYER_SELECTOR)?._vidplyPlayer ?? null;

const trackIndex = (button) => {
    const index = Number.parseInt(button.dataset.mpcEpisodeIndex ?? '', 10);
    return Number.isInteger(index) && index >= 0 ? index : 0;
};

const activeIndex = (player) => {
    const index = player.playlistManager?.currentIndex;
    return Number.isInteger(index) && index >= 0 ? index : 0;
};

const isPlaying = (player) => (typeof player.isPlaying === 'function' ? Boolean(player.isPlaying()) : false);

const paintButton = (button, playing) => {
    const label = playing
        ? button.dataset.mpcEpisodeLabelPause
        : button.dataset.mpcEpisodeLabelPlay;
    const title = button.dataset.mpcEpisodeTitle;

    button.dataset.state = playing ? 'playing' : 'paused';
    if (label) {
        button.setAttribute('aria-label', title ? `${label}: ${title}` : label);
    }
};

const itemAt = (root, index) => root.querySelector(`[data-mpc-episode-item="${index}"]`);

/**
 * A row title sits one heading level deeper than the card above the player, so
 * a clone has to take over the tag of the title it replaces.
 */
const retagTitle = (header, tagName) => {
    const title = header.querySelector(TITLE_SELECTOR);
    if (!title || title.tagName === tagName) {
        return;
    }

    const replacement = document.createElement(tagName);
    replacement.className = title.className;
    replacement.append(...title.childNodes);
    title.replaceWith(replacement);
};

/**
 * The card above the player shows the episode that is playing. Cloning the list
 * row leaves the server-rendered markup as the single source of episode data,
 * so nothing has to be re-formatted here. A layout without a list (the plain
 * card) has no row to clone and keeps whatever the template rendered.
 */
const syncCurrentEpisode = (root, index) => {
    if (root.dataset.mpcEpisodeCurrent === String(index)) {
        return;
    }

    const header = root.querySelector(CURRENT_HEADER_SELECTOR);
    const source = itemAt(root, index);
    const sourceHeader = source?.querySelector('.mpc-episode-header');
    if (!header || !sourceHeader) {
        return;
    }

    // Auto-advance can swap the card while its play button still has focus.
    const keepFocus = header.contains(document.activeElement);

    const nextHeader = sourceHeader.cloneNode(true);
    retagTitle(nextHeader, header.querySelector(TITLE_SELECTOR)?.tagName ?? 'H3');
    header.replaceWith(nextHeader);

    if (keepFocus) {
        nextHeader.querySelector(PLAY_BUTTON_SELECTOR)?.focus();
    }

    const cover = root.querySelector(CURRENT_COVER_SELECTOR);
    const sourceCover = source.querySelector('.mpc-episode-cover');
    if (cover && sourceCover) {
        cover.replaceWith(sourceCover.cloneNode(true));
    }

    root.dataset.mpcEpisodeCurrent = String(index);
};

/**
 * One player drives every button in the layout, so the playing state belongs to
 * the selected track only — every other button falls back to its play label.
 */
const paint = (root, player) => {
    const active = activeIndex(player);
    const playing = isPlaying(player);

    syncCurrentEpisode(root, active);

    root.querySelectorAll(PLAY_BUTTON_SELECTOR).forEach((button) => {
        const isActive = trackIndex(button) === active;
        paintButton(button, isActive && playing);

        const item = button.closest(ITEM_SELECTOR);
        if (!item) {
            return;
        }
        if (isActive) {
            item.setAttribute('aria-current', 'true');
        } else {
            item.removeAttribute('aria-current');
        }
    });
};

const subscribe = (root, player) => {
    if (typeof player.on !== 'function') {
        return false;
    }
    if (!wiredPlayers.has(player)) {
        wiredPlayers.add(player);
        ['play', 'pause', 'ended', 'playlisttrackchange'].forEach((event) => {
            player.on(event, () => paint(root, player));
        });
    }

    paint(root, player);
    return true;
};

const handleClick = (button) => {
    const root = button.closest(ROOT_SELECTOR);
    const player = findPlayer(root);
    if (!player) {
        return;
    }

    subscribe(root, player);

    const playlist = player.playlistManager;
    const index = trackIndex(button);

    if (Array.isArray(playlist?.tracks) && playlist.tracks.length > 1 && index !== playlist.currentIndex) {
        Promise.resolve(playlist.play(index, true)).catch(() => {
            // The player reports load failures through its own error handling.
        });
        return;
    }

    if (isPlaying(player)) {
        player.pause?.();
    } else {
        player.play?.();
    }
};

const attachRoot = (root, attempt = 0) => {
    if (wiredRoots.has(root)) {
        return;
    }

    const player = findPlayer(root);
    if (player && subscribe(root, player)) {
        wiredRoots.add(root);
        return;
    }

    if (attempt < ATTACH_DELAYS.length) {
        window.setTimeout(() => attachRoot(root, attempt + 1), ATTACH_DELAYS[attempt]);
    }
};

const rootsIn = (scope) => {
    const roots = Array.from(scope.querySelectorAll(ROOT_SELECTOR));
    if (scope instanceof Element && scope.matches(ROOT_SELECTOR)) {
        roots.unshift(scope);
    }

    return roots;
};

/**
 * @param {ParentNode} [root=document]
 */
const scan = (root = document) => {
    const scope = root instanceof Element || root instanceof Document ? root : document;
    rootsIn(scope).forEach((episodeRoot) => attachRoot(episodeRoot));
};

/* Delegated, because the card above the player swaps in a cloned button
   whenever the track changes. */
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element
        ? event.target.closest(PLAY_BUTTON_SELECTOR)
        : null;

    if (button) {
        handleClick(button);
    }
});

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
