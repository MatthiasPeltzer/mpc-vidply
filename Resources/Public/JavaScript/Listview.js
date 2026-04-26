/**
 * VidPly Listview: shelf scroller, sort, client-side row pagination, card fade-in.
 * No external dependencies. ESM module.
 */

const SHELF_SELECTOR = '[data-vidply-shelf]';
const PREV_SELECTOR = '[data-vidply-shelf-prev]';
const NEXT_SELECTOR = '[data-vidply-shelf-next]';
const LIST_ROOT = '[data-mpc-vidply-list-root]';
const SORT_SELECT = '[data-mpc-vidply-row-sort]';
const SECTION_SELECTOR = '.mpc-vidply-listview-section';
const PAGINATE_ROOT = '[data-mpc-vidply-paginate]';
const PAGER_NAV = '[data-mpc-vidply-pager-nav]';

const MAX_NUMERIC_PAGE_BUTTONS = 9;

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

const getSortLocale = () => {
    const l = (typeof document !== 'undefined' && document.documentElement.lang) || '';
    const t = l.trim();
    return t || undefined;
};

const sortListItemNodes = (listRoot, mode) => {
    const items = Array.from(listRoot.querySelectorAll(':scope > li'));
    if (items.length <= 1) {
        return;
    }
    const locale = getSortLocale();
    let collator = null;
    if (typeof Intl !== 'undefined' && typeof Intl.Collator === 'function' && mode === 'title_asc') {
        try {
            collator = new Intl.Collator(locale, { sensitivity: 'base' });
        } catch {
            collator = null;
        }
    }

    const nTitle = (el) => (el.dataset.mpcVidplyTitle || '').trim();
    const nOrder = (el) => parseInt(el.dataset.mpcVidplyOrder, 10) || 0;
    const nCr = (el) => parseInt(el.dataset.mpcVidplyCrdate, 10) || 0;

    const compare = (a, b) => {
        if (mode === 'title_asc') {
            if (collator) {
                return collator.compare(nTitle(a), nTitle(b));
            }
            return nTitle(a).localeCompare(nTitle(b), locale, { sensitivity: 'base' });
        }
        if (mode === 'crdate_desc') {
            return nCr(b) - nCr(a);
        }
        return nOrder(a) - nOrder(b);
    };
    items.sort(compare);
    for (const li of items) {
        listRoot.appendChild(li);
    }
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

const formatPageOfTemplate = (template, page, total) => {
    if (typeof template !== 'string' || template === '') {
        return 'Page ' + String(page) + ' of ' + String(total);
    }
    return template.replace(/\{0\}/g, String(page)).replace(/\{1\}/g, String(total));
};

const paginateReset = new WeakMap();

/**
 * @param {HTMLElement} container
 */
const createRowPagination = (container) => {
    const itemList = container.querySelector(LIST_ROOT);
    const nav = container.querySelector(PAGER_NAV);
    if (!itemList || !nav) {
        return;
    }
    const perPage = Math.max(1, parseInt(container.dataset.mpcVidplyPerPage, 10) || 12);
    const labelPrev = container.dataset.mpcPagerLblPrev || 'Previous';
    const labelNext = container.dataset.mpcPagerLblNext || 'Next';
    const pageOfTpl = container.dataset.mpcPagerLblPageof || 'Page {0} of {1}';
    const navAriaTpl =
        container.dataset.mpcPagerLblNavAria || 'Pagination, page {0} of {1}';

    let currentPage = 1;

    const getItems = () => Array.from(itemList.querySelectorAll(':scope > li'));

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

    const renderPage = (page, focusFirstItem) => {
        const items = getItems();
        const total = items.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        if (page < 1) {
            page = 1;
        }
        if (page > totalPages) {
            page = totalPages;
        }
        currentPage = page;
        const start = (currentPage - 1) * perPage;
        const end = start + perPage;
        for (let i = 0; i < total; i++) {
            if (i >= start && i < end) {
                items[i].removeAttribute('hidden');
            } else {
                items[i].setAttribute('hidden', '');
            }
        }
        if (focusFirstItem && items[start]) {
            const link = items[start].querySelector('a, button');
            if (link instanceof HTMLElement) {
                link.focus();
            }
        }
        setShelfScrollAfterPageChange();
        renderControls();
    };

    const renderControls = () => {
        const items = getItems();
        const total = items.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        if (totalPages <= 1) {
            nav.innerHTML = '';
            nav.removeAttribute('aria-label');
            return;
        }

        nav.setAttribute(
            'aria-label',
            formatPageOfTemplate(navAriaTpl, currentPage, totalPages)
        );

        const ul = document.createElement('ul');
        ul.className = 'mpc-vidply-pager__list';
        nav.innerHTML = '';
        nav.appendChild(ul);

        const goTo = (p) => {
            renderPage(p, true);
        };

        const addBtn = (text, disabled, isActive, isCurrent, onClick) => {
            const li = document.createElement('li');
            li.className = 'mpc-vidply-pager__item' + (isActive ? ' mpc-vidply-pager__item--active' : '');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mpc-vidply-pager__btn' + (isActive ? ' mpc-vidply-pager__btn--active' : '');
            btn.textContent = text;
            btn.disabled = Boolean(disabled);
            if (isCurrent) {
                btn.setAttribute('aria-current', 'page');
            }
            if (!disabled) {
                btn.addEventListener('click', onClick);
            }
            li.appendChild(btn);
            ul.appendChild(li);
        };

        const compact = totalPages > MAX_NUMERIC_PAGE_BUTTONS;

        addBtn(labelPrev, currentPage === 1, false, false, () => {
            if (currentPage > 1) {
                goTo(currentPage - 1);
            }
        });

        if (compact) {
            const li = document.createElement('li');
            li.className = 'mpc-vidply-pager__item mpc-vidply-pager__item--status';
            const sp = document.createElement('p');
            sp.className = 'mpc-vidply-pager__status';
            sp.setAttribute('role', 'status');
            sp.textContent = formatPageOfTemplate(pageOfTpl, currentPage, totalPages);
            li.appendChild(sp);
            ul.appendChild(li);
        } else {
            for (let i = 1; i <= totalPages; i++) {
                const isCur = i === currentPage;
                addBtn(
                    String(i),
                    isCur,
                    isCur,
                    isCur,
                    isCur
                        ? () => {}
                        : () => {
                              goTo(i);
                          }
                );
            }
        }

        addBtn(labelNext, currentPage === totalPages, false, false, () => {
            if (currentPage < totalPages) {
                goTo(currentPage + 1);
            }
        });
    };

    const resetToFirst = () => {
        renderPage(1, false);
    };

    paginateReset.set(container, resetToFirst);
    renderPage(1, false);
};

const initListPagination = () => {
    document.querySelectorAll(PAGINATE_ROOT).forEach((el) => {
        if (el instanceof HTMLElement) {
            createRowPagination(el);
        }
    });
};

const initSortSelectValues = () => {
    document.querySelectorAll(SORT_SELECT).forEach((el) => {
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

const initSortSelects = () => {
    initSortSelectValues();
    document.querySelectorAll(SORT_SELECT).forEach((el) => {
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

const initCardFadeIn = () => {
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
    document.querySelectorAll('.mpc-vidply-card').forEach((card) => observer.observe(card));
};

const init = () => {
    initListPagination();
    initSortSelects();
    document.querySelectorAll(SHELF_SELECTOR).forEach((track) => initShelf(track));
    initCardFadeIn();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}

export { init };
