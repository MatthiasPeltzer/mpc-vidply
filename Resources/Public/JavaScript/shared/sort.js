/**
 * Locale-aware client-side sorting of a server-rendered list.
 *
 * The comparators themselves stay with their list — the episode list and the
 * listview rows carry different data attributes and offer different orders —
 * but locale detection, collator setup and reordering are the same everywhere.
 */

/**
 * @returns {string|undefined} The document language, or undefined so `Intl`
 *          falls back to the browser locale.
 */
export const documentLocale = () => document.documentElement.lang?.trim() || undefined;

/**
 * @param {string|undefined} locale
 * @returns {Intl.Collator|null} Null when `Intl` is unavailable or rejects the locale.
 */
export const createCollator = (locale) => {
    if (typeof Intl === 'undefined' || typeof Intl.Collator !== 'function') {
        return null;
    }
    try {
        return new Intl.Collator(locale, { sensitivity: 'base' });
    } catch {
        return null;
    }
};

/**
 * @param {Intl.Collator|null} collator
 * @param {string|undefined} locale
 */
export const compareText = (a, b, collator, locale) =>
    collator ? collator.compare(a, b) : a.localeCompare(b, locale, { sensitivity: 'base' });

/**
 * Reorder the direct children of a list in place.
 *
 * @param {Element} list
 * @param {(a: Element, b: Element) => number} comparator
 */
export const sortChildren = (list, comparator) => {
    const items = Array.from(list.querySelectorAll(':scope > li'));
    if (items.length < 2) {
        return;
    }

    items.sort(comparator);
    for (const item of items) {
        list.appendChild(item);
    }
};
