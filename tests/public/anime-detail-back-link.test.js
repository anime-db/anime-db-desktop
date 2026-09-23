/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

'use strict';

// Exercises just the "← Catalog" link wiring in anime-detail.js (issue #719) against a minimal
// DOM shaped like the link app/templates/anime/show.html.twig renders. Requiring the real module
// also registers the label-widget and open-folder-button controls, but neither is mounted here —
// mountControls() only dispatches htmx:load on the link itself, and mounting is a no-op for any
// other data-control name not present in this test's DOM (existing behavior, unrelated to this
// test). controller.js itself is required once at file scope — see controller.test.js for why a
// fresh require() per test would leak document-level listeners.

require('../../app/public/js/controller.js');

function setUpDom() {
    document.body.innerHTML = '<a href="/" data-control="catalog-back-link">Catalog</a>';
}

function setHistoryLength(length) {
    Object.defineProperty(window.history, 'length', { value: length, configurable: true });
}

function setReferrer(referrer) {
    Object.defineProperty(document, 'referrer', { value: referrer, configurable: true });
}

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function loadModule() {
    jest.resetModules();
    require('../../app/public/js/anime-detail.js');
    mountControls();
}

function dispatchClick(link) {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    link.dispatchEvent(event);

    return event;
}

beforeEach(() => {
    setUpDom();
    setReferrer(`${window.location.origin}/`);
});

afterEach(() => {
    jest.restoreAllMocks();
});

test('clicking the link goes back through history when arriving from the catalog', () => {
    setHistoryLength(2);
    const backSpy = jest.spyOn(window.history, 'back').mockImplementation(() => {});

    loadModule();
    const event = dispatchClick(document.querySelector('[data-control="catalog-back-link"]'));

    expect(backSpy).toHaveBeenCalledTimes(1);
    expect(event.defaultPrevented).toBe(true);
});

test('the link falls back to plain navigation when the window has no previous entry', () => {
    setHistoryLength(1);
    const backSpy = jest.spyOn(window.history, 'back').mockImplementation(() => {});

    loadModule();
    const event = dispatchClick(document.querySelector('[data-control="catalog-back-link"]'));

    expect(backSpy).not.toHaveBeenCalled();
    expect(event.defaultPrevented).toBe(false);
});

test('the link falls back to plain navigation when the previous page is not the catalog', () => {
    setHistoryLength(2);
    setReferrer(`${window.location.origin}/settings/sync-review`);
    const backSpy = jest.spyOn(window.history, 'back').mockImplementation(() => {});

    loadModule();
    const event = dispatchClick(document.querySelector('[data-control="catalog-back-link"]'));

    expect(backSpy).not.toHaveBeenCalled();
    expect(event.defaultPrevented).toBe(false);
});

test('the link falls back to plain navigation when there is no referrer', () => {
    setHistoryLength(2);
    setReferrer('');
    const backSpy = jest.spyOn(window.history, 'back').mockImplementation(() => {});

    loadModule();
    const event = dispatchClick(document.querySelector('[data-control="catalog-back-link"]'));

    expect(backSpy).not.toHaveBeenCalled();
    expect(event.defaultPrevented).toBe(false);
});
