/**
 * Client-side pagination of a server-rendered list.
 *
 * Rows outside the current page get the `hidden` attribute, so they leave the
 * tab order and the accessibility tree instead of merely being invisible.
 * Paging moves focus to the first row of the new page.
 *
 * The episode list and the listview rows use different class names but the
 * same algorithm and the same markup shape, so the caller supplies labels and
 * class names and gets the controls rendered for it.
 */

/** Beyond this many pages the numeric buttons give way to a "Page X of Y" status. */
const MAX_NUMERIC_PAGE_BUTTONS = 9;

/**
 * @param {string} template A label with `{0}` for the page and `{1}` for the total
 */
export const formatPageLabel = (template, page, total) => {
    if (typeof template !== 'string' || template === '') {
        return `Page ${page} of ${total}`;
    }
    return template.replace(/\{0\}/g, String(page)).replace(/\{1\}/g, String(total));
};

const listItems = (list) => Array.from(list.querySelectorAll(':scope > li'));

/**
 * @typedef {object} PaginationClasses
 * @property {string} list        Class of the generated `<ul>`
 * @property {string} item        Class of every `<li>`
 * @property {string} itemActive  Added to the `<li>` of the current page, if any
 * @property {string} itemStatus  Added to the `<li>` carrying the compact status
 * @property {string} button      Class of every `<button>`
 * @property {string} buttonActive Added to the current page's `<button>`
 * @property {string} status      Class of the compact status paragraph
 *
 * @typedef {object} PaginationLabels
 * @property {string} prev
 * @property {string} next
 * @property {string} pageOf   Template for the compact status
 * @property {string} navAria  Template for the nav's accessible name
 *
 * @param {object} options
 * @param {Element} options.list Element whose `<li>` children are paged
 * @param {Element} options.nav  Element the controls are rendered into
 * @param {number} options.perPage
 * @param {PaginationLabels} options.labels
 * @param {PaginationClasses} options.classes
 * @param {() => void} [options.onPageChange] Runs after every page render
 * @returns {{reset: () => void, reveal: (predicate: (item: Element) => boolean) => void}}
 */
export const createPagination = ({ list, nav, perPage, labels, classes, onPageChange }) => {
    const pageSize = Math.max(1, perPage);
    let currentPage = 1;

    const totalPages = (count) => Math.max(1, Math.ceil(count / pageSize));

    const addButton = (parent, text, { disabled, current, onClick }) => {
        const item = document.createElement('li');
        item.className = classes.item + (current && classes.itemActive ? ` ${classes.itemActive}` : '');

        const button = document.createElement('button');
        button.type = 'button';
        button.className = classes.button + (current && classes.buttonActive ? ` ${classes.buttonActive}` : '');
        button.textContent = text;
        button.disabled = Boolean(disabled);
        if (current) {
            button.setAttribute('aria-current', 'page');
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

        nav.setAttribute('aria-label', formatPageLabel(labels.navAria, currentPage, pages));

        const pageList = document.createElement('ul');
        pageList.className = classes.list;
        nav.appendChild(pageList);

        addButton(pageList, labels.prev, {
            disabled: currentPage === 1,
            current: false,
            onClick: () => renderPage(currentPage - 1, true),
        });

        if (pages > MAX_NUMERIC_PAGE_BUTTONS) {
            const item = document.createElement('li');
            item.className = `${classes.item} ${classes.itemStatus}`;

            const status = document.createElement('p');
            status.className = classes.status;
            status.setAttribute('role', 'status');
            status.textContent = formatPageLabel(labels.pageOf, currentPage, pages);

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

        addButton(pageList, labels.next, {
            disabled: currentPage === pages,
            current: false,
            onClick: () => renderPage(currentPage + 1, true),
        });
    };

    function renderPage(page, focusFirstItem) {
        const items = listItems(list);
        const pages = totalPages(items.length);
        currentPage = Math.min(Math.max(page, 1), pages);

        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;

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

        onPageChange?.();
        renderControls();
    }

    renderPage(1, false);

    return {
        reset: () => renderPage(1, false),
        reveal: (predicate) => {
            const position = listItems(list).findIndex(predicate);
            if (position < 0) {
                return;
            }

            const page = Math.floor(position / pageSize) + 1;
            if (page !== currentPage) {
                renderPage(page, false);
            }
        },
    };
};
