/**
 * VidPly Listview: shelf scroller, sort, client-side row pagination, card fade-in.
 * No external dependencies. ESM module.
 */

import { bootstrap, resolveScope } from './shared/bootstrap.js';
import { createPagination } from './shared/pagination.js';
import { compareText, createCollator, documentLocale, sortChildren } from './shared/sort.js';

const SHELF_SELECTOR = '[data-vidply-shelf]';
const PREV_SELECTOR = '[data-vidply-shelf-prev]';
const NEXT_SELECTOR = '[data-vidply-shelf-next]';
const LIST_ROOT = '[data-mpc-vidply-list-root]';
const SORT_SELECT = '[data-mpc-vidply-row-sort]';
const SECTION_SELECTOR = '.mpc-vidply-listview-section';
const PAGINATE_ROOT = '[data-mpc-vidply-paginate]';
const PAGER_NAV = '[data-mpc-vidply-pager-nav]';

const PAGER_CLASSES = {
    list: 'mpc-vidply-pager__list',
    item: 'mpc-vidply-pager__item',
    itemActive: 'mpc-vidply-pager__item--active',
    itemStatus: 'mpc-vidply-pager__item--status',
    button: 'mpc-vidply-pager__btn',
    buttonActive: 'mpc-vidply-pager__btn--active',
    status: 'mpc-vidply-pager__status',
};

const prefersReducedMotion = () =>
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const getScrollStep = (track) => {
    const firstItem =
        track.querySelector(':scope > li:not([hidden])') ||
        track.querySelector(':scope > li');
    const gap = parseFloat(window.getComputedStyle(track).columnGap || '16') || 16;
    if (firstItem) {
        return firstItem.getBoundingClientRect().width + gap;
    }
    return Math.max(240, track.clientWidth * 0.8);
};

const updateArrows = (track, prevBtn, nextBtn) => {
    const max = track.scrollWidth - track.clientWidth;
    const atStart = track.scrollLeft <= 2;
    const atEnd = track.scrollLeft >= max - 2;
    if (prevBtn) {
        prevBtn.setAttribute('aria-disabled', atStart ? 'true' : 'false');
        prevBtn.disabled = atStart;
    }
    if (nextBtn) {
        nextBtn.setAttribute('aria-disabled', atEnd ? 'true' : 'false');
        nextBtn.disabled = atEnd;
    }
};

const scrollByPage = (track, direction) => {
    const step = getScrollStep(track);
    const delta = direction * step * Math.max(1, Math.floor(track.clientWidth / step) || 1);
    track.scrollBy({
        left: delta,
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
    });
};

const sortListItemNodes = (listRoot, mode) => {
    const locale = documentLocale();
    const collator = mode === 'title_asc' ? createCollator(locale) : null;

    const nTitle = (el) => (el.dataset.mpcVidplyTitle || '').trim();
    const nOrder = (el) => parseInt(el.dataset.mpcVidplyOrder, 10) || 0;
    const nCr = (el) => parseInt(el.dataset.mpcVidplyCrdate, 10) || 0;

    sortChildren(listRoot, (a, b) => {
        if (mode === 'title_asc') {
            return compareText(nTitle(a), nTitle(b), collator, locale);
        }
        if (mode === 'crdate_desc') {
            return nCr(b) - nCr(a);
        }
        return nOrder(a) - nOrder(b);
    });
};

const afterListReorder = (listRoot) => {
    if (!(listRoot instanceof HTMLElement)) {
        return;
    }
    if (listRoot.hasAttribute('data-vidply-shelf')) {
        listRoot.scrollLeft = 0;
        const wrapper = listRoot.closest('.mpc-vidply-listview-shelf-wrapper') || listRoot.parentElement;
        const prevBtn = wrapper ? wrapper.querySelector(PREV_SELECTOR) : null;
        const nextBtn = wrapper ? wrapper.querySelector(NEXT_SELECTOR) : null;
        requestAnimationFrame(() => updateArrows(listRoot, prevBtn, nextBtn));
    }
};

const paginateReset = new WeakMap();

/**
 * @param {HTMLElement} container
 */
const createRowPagination = (container) => {
    if (container.dataset.mpcVidplyPagerBound === '1') {
        return;
    }
    const itemList = container.querySelector(LIST_ROOT);
    const nav = container.querySelector(PAGER_NAV);
    if (!itemList || !nav) {
        return;
    }
    container.dataset.mpcVidplyPagerBound = '1';

    const setShelfScrollAfterPageChange = () => {
        if (!itemList.hasAttribute('data-vidply-shelf')) {
            return;
        }
        itemList.scrollLeft = 0;
        const wrap = itemList.closest('.mpc-vidply-listview-shelf-wrapper') || itemList.parentElement;
        const pBtn = wrap ? wrap.querySelector(PREV_SELECTOR) : null;
        const nBtn = wrap ? wrap.querySelector(NEXT_SELECTOR) : null;
        requestAnimationFrame(() => updateArrows(itemList, pBtn, nBtn));
    };

    const pager = createPagination({
        list: itemList,
        nav,
        perPage: parseInt(container.dataset.mpcVidplyPerPage, 10) || 12,
        labels: {
            prev: container.dataset.mpcPagerLblPrev || 'Previous',
            next: container.dataset.mpcPagerLblNext || 'Next',
            pageOf: container.dataset.mpcPagerLblPageof || 'Page {0} of {1}',
            navAria: container.dataset.mpcPagerLblNavAria || 'Pagination, page {0} of {1}',
        },
        classes: PAGER_CLASSES,
        onPageChange: setShelfScrollAfterPageChange,
    });

    paginateReset.set(container, pager.reset);
};

const initListPagination = (scope) => {
    scope.querySelectorAll(PAGINATE_ROOT).forEach((el) => {
        if (el instanceof HTMLElement) {
            createRowPagination(el);
        }
    });
};

const initSortSelectValues = (scope) => {
    scope.querySelectorAll(SORT_SELECT).forEach((el) => {
        if (!(el instanceof HTMLSelectElement)) {
            return;
        }
        const section = el.closest(SECTION_SELECTOR);
        if (!section) {
            return;
        }
        const def = section.dataset.mpcVidplyDefaultSort;
        if (!def) {
            return;
        }
        if (Array.from(el.options).some((o) => o.value === def)) {
            el.value = def;
        }
    });
};

const initSortSelects = (scope) => {
    initSortSelectValues(scope);
    scope.querySelectorAll(SORT_SELECT).forEach((el) => {
        if (!(el instanceof HTMLSelectElement) || el.dataset.mpcVidplySortBound === '1') {
            return;
        }
        el.dataset.mpcVidplySortBound = '1';
        el.addEventListener('change', () => {
            const section = el.closest(SECTION_SELECTOR);
            const listRoot = section ? section.querySelector(LIST_ROOT) : null;
            if (!(listRoot instanceof HTMLElement)) {
                return;
            }
            sortListItemNodes(listRoot, el.value);
            afterListReorder(listRoot);
            const pRoot = listRoot.closest(PAGINATE_ROOT);
            const reset = pRoot && paginateReset.get(pRoot);
            if (typeof reset === 'function') {
                reset();
            }
        });
    });
};

const initShelf = (track) => {
    if (!(track instanceof HTMLElement) || track.dataset.vidplyShelfBound === '1') {
        return;
    }
    track.dataset.vidplyShelfBound = '1';

    const wrapper = track.closest('.mpc-vidply-listview-shelf-wrapper') || track.parentElement;
    if (!wrapper) {
        return;
    }

    const prevBtn = wrapper.querySelector(PREV_SELECTOR);
    const nextBtn = wrapper.querySelector(NEXT_SELECTOR);

    if (prevBtn) {
        prevBtn.addEventListener('click', () => scrollByPage(track, -1));
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', () => scrollByPage(track, 1));
    }

    track.addEventListener('scroll', () => updateArrows(track, prevBtn, nextBtn), { passive: true });

    track.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            scrollByPage(track, 1);
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            scrollByPage(track, -1);
        } else if (event.key === 'Home') {
            event.preventDefault();
            track.scrollTo({ left: 0, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        } else if (event.key === 'End') {
            event.preventDefault();
            track.scrollTo({
                left: track.scrollWidth,
                behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            });
        }
    });

    requestAnimationFrame(() => updateArrows(track, prevBtn, nextBtn));

    const ro = typeof ResizeObserver !== 'undefined'
        ? new ResizeObserver(() => updateArrows(track, prevBtn, nextBtn))
        : null;
    if (ro) {
        ro.observe(track);
    } else {
        window.addEventListener('resize', () => updateArrows(track, prevBtn, nextBtn), { passive: true });
    }
};

const initCardFadeIn = (scope) => {
    if (prefersReducedMotion() || typeof IntersectionObserver === 'undefined') {
        return;
    }
    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-in-view');
                    observer.unobserve(entry.target);
                }
            }
        },
        { rootMargin: '0px 0px -10% 0px', threshold: 0.1 }
    );
    scope.querySelectorAll('.mpc-vidply-card').forEach((card) => observer.observe(card));
};

/**
 * Initialize every listview feature within a DOM subtree.
 *
 * @param {ParentNode} [root=document]
 */
const init = (root = document) => {
    const scope = resolveScope(root);
    initListPagination(scope);
    initSortSelects(scope);
    scope.querySelectorAll(SHELF_SELECTOR).forEach((track) => initShelf(track));
    initCardFadeIn(scope);
};

bootstrap(init);

export { init };
