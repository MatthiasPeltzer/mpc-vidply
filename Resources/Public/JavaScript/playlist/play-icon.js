/**
 * Custom play overlay icons: a server-sanitized inline SVG (from a
 * `<template class="mpc-vidply-play-inline-svg">`) or an icon URL applied as a
 * CSS custom property.
 */

import {sanitizeCssUrl} from '../shared/url-safety.js';

export function getInlinePlaySvgMarkup(wrapperElement) {
    const wrapper = wrapperElement?.closest?.('.vidply-wrapper') || wrapperElement;
    const tpl = wrapper?.querySelector?.('template.mpc-vidply-play-inline-svg');
    const html = tpl?.innerHTML?.trim?.() || '';
    return html !== '' ? html : null;
}

export function parseSvgFromMarkup(svgMarkup) {
    if (!svgMarkup) return null;
    const parser = new DOMParser();
    const doc = parser.parseFromString(svgMarkup, 'image/svg+xml');
    const svg = doc.querySelector('svg');
    return svg || null;
}

function replaceVidplyPlayOverlaySvg(wrapperElement) {
    const svgMarkup = getInlinePlaySvgMarkup(wrapperElement);
    if (!svgMarkup) return;

    const sourceSvg = parseSvgFromMarkup(svgMarkup);
    if (!sourceSvg) return;

    const targets = (wrapperElement || document).querySelectorAll?.('svg.vidply-play-overlay');
    targets?.forEach?.((target) => {
        if (!target || target.dataset?.mpcVidplyIconReplaced === '1') return;

        const viewBox = sourceSvg.getAttribute('viewBox');
        if (viewBox) {
            target.setAttribute('viewBox', viewBox);
        }
        while (target.firstChild) target.removeChild(target.firstChild);
        for (const child of Array.from(sourceSvg.childNodes)) {
            target.appendChild(child.cloneNode(true));
        }
        target.dataset.mpcVidplyIconReplaced = '1';
    });
}

/**
 * Apply the custom play-overlay icon as a CSS custom property at runtime.
 *
 * Previously emitted as an inline style attribute in Player.html; setting it
 * via element.style here keeps the markup free of inline styles so the CSP no
 * longer needs style-src 'unsafe-inline'. The wrapper carries the (already
 * server-sanitized) URL in data-vidply-play-icon; we re-validate client-side.
 */
export function applyWrapperPlayIconVar(wrapper) {
    if (!wrapper || wrapper.hasAttribute('data-vidply-play-inline-svg')) return;
    const safe = sanitizeCssUrl(wrapper.dataset.vidplyPlayIcon);
    if (safe) {
        wrapper.style.setProperty('--mpc-vidply-play-overlay-icon', `url("${safe}")`);
    }
}

export function observeVidplyOverlays(wrapperElement) {
    const wrapper = wrapperElement?.closest?.('.vidply-wrapper') || wrapperElement;
    if (!wrapper) return;
    if (!wrapper.hasAttribute('data-vidply-play-inline-svg')) return;

    // Initial attempt
    replaceVidplyPlayOverlaySvg(wrapper);

    wrapper._mpcVidplyPlayIconObserver?.disconnect();
    const observer = new MutationObserver(() => {
        replaceVidplyPlayOverlaySvg(wrapper);
    });
    observer.observe(wrapper, {childList: true, subtree: true});
    wrapper._mpcVidplyPlayIconObserver = observer;
}
