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

/* Beyond this many pages the numeric buttons give way to a "Page X of Y" status. */
const MAX_NUMERIC_PAGE_BUTTONS = 9;

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

const listItems = (list) => Array.from(list.querySelectorAll(':scope > li'));

const documentLocale = () => document.documentElement.lang?.trim() || undefined;

/**
 * Rows without a publish date sort last in both date modes, mirroring the
 * server-side default order.
 */
const compareEpisodeItems = (mode) => {
    const locale = documentLocale();
    let collator = null;
    if (mode === 'title_asc' && typeof Intl !== 'undefined' && typeof Intl.Collator === 'function') {
        try {
            collator = new Intl.Collator(locale, { sensitivity: 'base' });
        } catch {
            collator = null;
        }
    }

    const order = (item) => Number.parseInt(item.dataset.mpcEpisodeItem ?? '', 10) || 0;
    const title = (item) => (item.dataset.mpcEpisodeSortTitle ?? '').trim();
    const date = (item) => (item.dataset.mpcEpisodeDate ?? '').trim();

    return (a, b) => {
        let comparison = 0;

        if (mode === 'title_asc') {
            comparison = collator
                ? collator.compare(title(a), title(b))
                : title(a).localeCompare(title(b), locale, { sensitivity: 'base' });
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
    const items = listItems(list);
    if (items.length < 2) {
        return;
    }

    items.sort(compareEpisodeItems(mode));
    items.forEach((item) => list.appendChild(item));
};

const formatPageLabel = (template, page, total) => {
    if (typeof template !== 'string' || template === '') {
        return `Page ${page} of ${total}`;
    }
    return template.replace(/\{0\}/g, String(page)).replace(/\{1\}/g, String(total));
};

/**
 * Client-side pagination of the episode list. Rows outside the current page get
 * the `hidden` attribute, which also takes them out of the tab order and the
 * accessibility tree.
 *
 * @param {HTMLElement} container
 * @returns {{reset: () => void, reveal: (index: number) => void}|null}
 */
const createPagination = (container) => {
    const list = container.querySelector(LIST_SELECTOR);
    const nav = container.querySelector(PAGER_NAV_SELECTOR);
    if (!list || !nav) {
        return null;
    }

    /* Fluid pads the label attributes with the whitespace of their variable block. */
    const label = (name, fallback) => (container.dataset[name] ?? '').trim() || fallback;

    const perPage = Math.max(1, Number.parseInt(container.dataset.mpcEpisodePerPage ?? '', 10) || 10);
    const labelPrev = label('mpcEpisodePagerLblPrev', 'Previous');
    const labelNext = label('mpcEpisodePagerLblNext', 'Next');
    const pageOfTemplate = label('mpcEpisodePagerLblPageof', 'Page {0} of {1}');
    const navAriaTemplate = label('mpcEpisodePagerLblNavAria', 'Episode list pagination, page {0} of {1}');

    let currentPage = 1;

    const totalPages = (count) => Math.max(1, Math.ceil(count / perPage));

    const addButton = (parent, text, { disabled, current, onClick }) => {
        const item = document.createElement('li');
        item.className = 'mpc-episode-pager-item';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'mpc-episode-pager-button';
        button.textContent = text;
        button.disabled = Boolean(disabled);
        if (current) {
            button.setAttribute('aria-current', 'page');
            button.classList.add('is-current');
        }
        if (!button.disabled) {
            button.addEventListener('click', onClick);
        }

        item.appendChild(button);
        parent.appendChild(item);
    };

    const renderControls = () => {
        const pages = totalPages(listItems(list).length);
        nav.textContent = '';

        if (pages <= 1) {
            nav.removeAttribute('aria-label');
            return;
        }

        nav.setAttribute('aria-label', formatPageLabel(navAriaTemplate, currentPage, pages));

        const pageList = document.createElement('ul');
        pageList.className = 'mpc-episode-pager-list';
        nav.appendChild(pageList);

        addButton(pageList, labelPrev, {
            disabled: currentPage === 1,
            current: false,
            onClick: () => renderPage(currentPage - 1, true),
        });

        if (pages > MAX_NUMERIC_PAGE_BUTTONS) {
            const item = document.createElement('li');
            item.className = 'mpc-episode-pager-item mpc-episode-pager-item--status';

            const status = document.createElement('p');
            status.className = 'mpc-episode-pager-status';
            status.setAttribute('role', 'status');
            status.textContent = formatPageLabel(pageOfTemplate, currentPage, pages);

            item.appendChild(status);
            pageList.appendChild(item);
        } else {
            for (let page = 1; page <= pages; page += 1) {
                const isCurrent = page === currentPage;
                addButton(pageList, String(page), {
                    disabled: isCurrent,
                    current: isCurrent,
                    onClick: () => renderPage(page, true),
                });
            }
        }

        addButton(pageList, labelNext, {
            disabled: currentPage === pages,
            current: false,
            onClick: () => renderPage(currentPage + 1, true),
        });
    };

    function renderPage(page, focusFirstItem) {
        const items = listItems(list);
        const pages = totalPages(items.length);
        currentPage = Math.min(Math.max(page, 1), pages);

        const start = (currentPage - 1) * perPage;
        const end = start + perPage;

        items.forEach((item, index) => {
            if (index >= start && index < end) {
                item.removeAttribute('hidden');
            } else {
                item.setAttribute('hidden', '');
            }
        });

        if (focusFirstItem) {
            const control = items[start]?.querySelector('button, a');
            if (control instanceof HTMLElement) {
                control.focus();
            }
        }

        renderControls();
    }

    renderPage(1, false);

    return {
        reset: () => renderPage(1, false),
        reveal: (index) => {
            const position = listItems(list).findIndex(
                (item) => Number.parseInt(item.dataset.mpcEpisodeItem ?? '', 10) === index
            );
            if (position < 0) {
                return;
            }

            const page = Math.floor(position / perPage) + 1;
            if (page !== currentPage) {
                renderPage(page, false);
            }
        },
    };
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
    const pager = container instanceof HTMLElement ? createPagination(container) : null;
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
        pagers.get(root)?.reveal(active);
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
    const scope = root instanceof Element || root instanceof Document ? root : document;
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
