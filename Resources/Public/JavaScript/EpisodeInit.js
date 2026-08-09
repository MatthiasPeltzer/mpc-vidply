/**
 * VidPly episode layouts: wires the episode play buttons to the layout's player,
 * drives the list's own sorting and pagination and toggles the long description
 * of a medium.
 *
 * The `episodes` layout renders one button per playlist track and suppresses the
 * player's own panel, so a click has to select the matching track rather than
 * just toggle playback. It also mirrors the selected track into the card above
 * the player. Sorting and paging only affect the list: rows are addressed by
 * their playlist track index, so the player's order is never touched.
 * No external dependencies. ESM module.
 */

import { bootstrap, resolveScope } from './shared/bootstrap.js';
import { createPagination } from './shared/pagination.js';
import { compareText, createCollator, documentLocale, sortChildren } from './shared/sort.js';

const PLAY_BUTTON_SELECTOR = '[data-mpc-episode-play]';
const ROOT_SELECTOR = '.mpc-episode';
const ITEM_SELECTOR = '[data-mpc-episode-item]';
const PLAYER_SELECTOR = '[data-vidply-init], [data-playlist]';
/* The card above the player, as opposed to the identically built list rows. */
const CURRENT_COVER_SELECTOR = ':scope > .mpc-episode-cover';
const CURRENT_HEADER_SELECTOR = ':scope > .mpc-episode-main > .mpc-episode-header';
const TITLE_SELECTOR = '.mpc-episode-title';
const BODY_SELECTOR = '.mpc-episode-body';
const LONGDESC_TOGGLE_SELECTOR = '[data-mpc-episode-longdesc-toggle]';
const LONGDESC_SELECTOR = '[data-mpc-episode-longdesc]';
const LONGDESC_TOGGLE_TEXT_SELECTOR = '.mpc-episode-longdesc-toggle-text';
const LIST_SECTION_SELECTOR = '.mpc-episode-list-section';
const LIST_SELECTOR = '[data-mpc-episode-list]';
const SORT_SELECT_SELECTOR = '[data-mpc-episode-sort]';
const PAGINATE_SELECTOR = '[data-mpc-episode-paginate]';
const PAGER_NAV_SELECTOR = '[data-mpc-episode-pager-nav]';

const PAGER_CLASSES = {
    list: 'mpc-episode-pager-list',
    item: 'mpc-episode-pager-item',
    itemActive: '',
    itemStatus: 'mpc-episode-pager-item--status',
    button: 'mpc-episode-pager-button',
    buttonActive: 'is-current',
    status: 'mpc-episode-pager-status',
};

/**
 * PlaylistInit assigns `_vidplyPlayer` during its own DOMContentLoaded pass, so
 * the player may not exist yet. Retry a few times to pick up playback the user
 * did not start from a card (auto-advance, the player's next/previous buttons).
 */
const ATTACH_DELAYS = [100, 300, 800, 2000];

const wiredPlayers = new WeakSet();
const wiredRoots = new WeakSet();
const wiredLists = new WeakSet();
/** Pager of an episode root, so `paint()` can page back to the playing episode. */
const pagers = new WeakMap();

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
 * Rows without a publish date sort last in both date modes, mirroring the
 * server-side default order.
 */
const compareEpisodeItems = (mode) => {
    const locale = documentLocale();
    const collator = mode === 'title_asc' ? createCollator(locale) : null;

    const order = (item) => Number.parseInt(item.dataset.mpcEpisodeItem ?? '', 10) || 0;
    const title = (item) => (item.dataset.mpcEpisodeSortTitle ?? '').trim();
    const date = (item) => (item.dataset.mpcEpisodeDate ?? '').trim();

    return (a, b) => {
        let comparison = 0;

        if (mode === 'title_asc') {
            comparison = compareText(title(a), title(b), collator, locale);
        } else if (mode === 'date_desc' || mode === 'date_asc') {
            const dateA = date(a);
            const dateB = date(b);
            if (dateA === '' || dateB === '') {
                comparison = dateA === dateB ? 0 : (dateA === '' ? 1 : -1);
            } else {
                comparison = dateA < dateB ? -1 : (dateA > dateB ? 1 : 0);
                if (mode === 'date_desc') {
                    comparison = -comparison;
                }
            }
        }

        return comparison !== 0 ? comparison : order(a) - order(b);
    };
};

const sortEpisodeItems = (list, mode) => {
    sortChildren(list, compareEpisodeItems(mode));
};

/**
 * Pager for the episode list of one layout.
 *
 * @param {HTMLElement} container
 * @returns {{reset: () => void, reveal: (predicate: (item: Element) => boolean) => void}|null}
 */
const createEpisodePagination = (container) => {
    const list = container.querySelector(LIST_SELECTOR);
    const nav = container.querySelector(PAGER_NAV_SELECTOR);
    if (!list || !nav) {
        return null;
    }

    /* Fluid pads the label attributes with the whitespace of their variable block. */
    const label = (name, fallback) => (container.dataset[name] ?? '').trim() || fallback;

    return createPagination({
        list,
        nav,
        perPage: Number.parseInt(container.dataset.mpcEpisodePerPage ?? '', 10) || 10,
        labels: {
            prev: label('mpcEpisodePagerLblPrev', 'Previous'),
            next: label('mpcEpisodePagerLblNext', 'Next'),
            pageOf: label('mpcEpisodePagerLblPageof', 'Page {0} of {1}'),
            navAria: label('mpcEpisodePagerLblNavAria', 'Episode list pagination, page {0} of {1}'),
        },
        classes: PAGER_CLASSES,
    });
};

/**
 * Sort dropdown and pager of one episode root. The server already renders the
 * preselected order, so loading only mirrors it into the select.
 */
const initList = (root) => {
    const section = root.querySelector(LIST_SECTION_SELECTOR);
    const list = section?.querySelector(LIST_SELECTOR);
    if (!list || wiredLists.has(list)) {
        return;
    }
    wiredLists.add(list);

    const container = section.querySelector(PAGINATE_SELECTOR);
    const pager = container instanceof HTMLElement ? createEpisodePagination(container) : null;
    if (pager) {
        pagers.set(root, pager);
    }

    const select = section.querySelector(SORT_SELECT_SELECTOR);
    if (!(select instanceof HTMLSelectElement)) {
        return;
    }

    const defaultSort = section.dataset.mpcEpisodeDefaultSort;
    if (defaultSort && Array.from(select.options).some((option) => option.value === defaultSort)) {
        select.value = defaultSort;
    }

    select.addEventListener('change', () => {
        sortEpisodeItems(list, select.value);
        pager?.reset();
    });
};

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
    nextHeader.removeAttribute('hidden');
    retagTitle(nextHeader, header.querySelector(TITLE_SELECTOR)?.tagName ?? 'H3');
    header.replaceWith(nextHeader);

    if (keepFocus) {
        nextHeader.querySelector(PLAY_BUTTON_SELECTOR)?.focus();
    }

    const cover = root.querySelector(CURRENT_COVER_SELECTOR);
    const sourceCover = source.querySelector('.mpc-episode-cover');
    if (cover && sourceCover) {
        const nextCover = sourceCover.cloneNode(true);
        nextCover.removeAttribute('hidden');
        cover.replaceWith(nextCover);
    }

    root.dataset.mpcEpisodeCurrent = String(index);
};

/**
 * One player drives every button in the layout, so the playing state belongs to
 * the selected track only — every other button falls back to its play label.
 */
const paint = (root, player, trackChanged = false) => {
    const active = activeIndex(player);
    const playing = isPlaying(player);

    // Playback runs through the whole playlist, so it can leave the page the
    // list currently shows — follow it there instead of losing the active row.
    // Only on a track change: play/pause and the initial paint must not pull the
    // list away from the page the visitor is looking at.
    if (trackChanged) {
        pagers.get(root)?.reveal(
            (item) => Number.parseInt(item.dataset.mpcEpisodeItem ?? '', 10) === active
        );
    }

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
        ['play', 'pause', 'ended'].forEach((event) => {
            player.on(event, () => paint(root, player));
        });
        player.on('playlisttrackchange', () => paint(root, player, true));
    }

    paint(root, player);
    return true;
};

/**
 * Disclosure for the medium's long description. The panel is found through the
 * card body rather than an id: the card above the player is a clone of a list
 * row, so an id would end up in the document twice.
 */
const toggleLongDescription = (button) => {
    const panel = button.closest(BODY_SELECTOR)?.querySelector(LONGDESC_SELECTOR);
    if (!panel) {
        return;
    }

    const expanded = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', String(expanded));
    panel.toggleAttribute('hidden', !expanded);

    const label = expanded
        ? button.dataset.mpcEpisodeLabelLess
        : button.dataset.mpcEpisodeLabelMore;
    if (!label) {
        return;
    }

    const text = button.querySelector(LONGDESC_TOGGLE_TEXT_SELECTOR);
    if (text) {
        text.textContent = label;
    }

    const title = button.dataset.mpcEpisodeTitle;
    button.setAttribute('aria-label', title ? `${label}: ${title}` : label);
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
    const scope = resolveScope(root);
    rootsIn(scope).forEach((episodeRoot) => {
        initList(episodeRoot);
        attachRoot(episodeRoot);
    });
};

/* Delegated, because the card above the player swaps in cloned buttons
   whenever the track changes. */
document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) {
        return;
    }

    const toggle = target.closest(LONGDESC_TOGGLE_SELECTOR);
    if (toggle) {
        toggleLongDescription(toggle);
        return;
    }

    const button = target.closest(PLAY_BUTTON_SELECTOR);
    if (button) {
        handleClick(button);
    }
});

bootstrap(scan, { fallbackToDocument: true });

window.MpcVidPlyEpisode = { scan };
