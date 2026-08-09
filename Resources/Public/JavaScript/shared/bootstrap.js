/**
 * The initialization trio every entry point needs: run once the document is
 * ready, run again for content injected later, and never trust the root a
 * caller passes in.
 */

/**
 * @param {unknown} root
 * @param {ParentNode} [fallback=document]
 * @returns {ParentNode}
 */
export const resolveScope = (root, fallback = document) =>
    root instanceof Element || root instanceof Document ? root : fallback;

/**
 * @param {() => void} handler
 */
export const onReady = (handler) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', handler, { once: true });
    } else {
        handler();
    }
};

/**
 * Content injected after load (Vue slides, AJAX-loaded sections) announces
 * itself with `mpc:dynamic-content:ready` and the subtree it added.
 *
 * @param {(root: ParentNode) => void} handler
 * @param {{ fallbackToDocument?: boolean }} [options] Whether an event without a
 *        usable root should rescan the whole document.
 */
export const onDynamicContent = (handler, { fallbackToDocument = false } = {}) => {
    document.addEventListener('mpc:dynamic-content:ready', (event) => {
        const root = event.detail?.root;
        if (root instanceof Element) {
            handler(root);
        } else if (fallbackToDocument) {
            handler(document);
        }
    });
};

/**
 * Wire both entry paths at once.
 *
 * @param {(root: ParentNode) => void} init
 * @param {{ onReady?: () => void, fallbackToDocument?: boolean }} [options]
 *        `onReady` replaces the initial call when a module needs more than a
 *        plain scan (theme sync, catch-up passes).
 */
export const bootstrap = (init, { onReady: readyHandler, fallbackToDocument = false } = {}) => {
    onDynamicContent(init, { fallbackToDocument });
    onReady(readyHandler ?? (() => init(document)));
};
