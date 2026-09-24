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

// Exercises the control registry itself (issue #734) against synthetic htmx:load/
// htmx:beforeCleanupElement events, shaped exactly like the ones htmx.js actually dispatches
// (bubbling CustomEvents fired on the settled/removed element itself — see triggerEvent() and
// makeAjaxLoadTask()/cleanUpElement() in node_modules/htmx.org/dist/htmx.js). Loaded once per
// file, like a real page's <script src="controller.js"> — see loadControllerOnce() below for why
// this must not be required fresh per test.

function dispatchHtmxLoad(root) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function dispatchBeforeCleanup(node) {
    node.dispatchEvent(new CustomEvent('htmx:beforeCleanupElement', { bubbles: true, detail: { elt: node } }));
}

// controller.js attaches its htmx:load/htmx:beforeCleanupElement listeners to `document` exactly
// once, for the lifetime of the module — re-requiring it per test (the usual jest.resetModules()
// pattern every other tests/public/*.test.js file uses for its own module) would attach one more
// listener per test without ever removing the previous one, since jest.resetModules() only clears
// the require() cache, not side effects a past require() already applied to `document`. A real
// page only ever loads this file once too (one <script> tag in base.html.twig), so requiring it
// once here — before jest.resetModules() runs for the first time — mirrors that.
require('../../app/assets/js/controller.js');

beforeEach(() => {
    document.body.innerHTML = '';
});

afterEach(() => {
    jest.restoreAllMocks();
    delete window.AppTranslations;
});

test('a control declared via data-control mounts on the initial htmx:load', () => {
    const mountFn = jest.fn();
    window.Controller.registerControl('test-initial', mountFn);
    document.body.innerHTML = '<div id="root" data-control="test-initial"></div>';

    dispatchHtmxLoad(document.body);

    const root = document.getElementById('root');
    expect(mountFn).toHaveBeenCalledTimes(1);
    expect(mountFn).toHaveBeenCalledWith(root);
});

test('a control on a fragment htmx swaps in later mounts from that fragment\'s own htmx:load', () => {
    const mountFn = jest.fn();
    window.Controller.registerControl('test-fragment', mountFn);
    document.body.innerHTML = '<div id="host"></div>';

    const fragment = document.createElement('section');
    fragment.setAttribute('data-control', 'test-fragment');
    document.getElementById('host').appendChild(fragment);

    dispatchHtmxLoad(fragment);

    expect(mountFn).toHaveBeenCalledTimes(1);
    expect(mountFn).toHaveBeenCalledWith(fragment);
});

test('several space-separated control names on the same element each mount independently', () => {
    const first = jest.fn();
    const second = jest.fn();
    window.Controller.registerControl('test-combo-first', first);
    window.Controller.registerControl('test-combo-second', second);
    document.body.innerHTML = '<div id="root" data-control="test-combo-first test-combo-second"></div>';

    dispatchHtmxLoad(document.body);

    expect(first).toHaveBeenCalledTimes(1);
    expect(second).toHaveBeenCalledTimes(1);
});

test('a repeat htmx:load on the same element does not mount the control a second time', () => {
    const mountFn = jest.fn();
    window.Controller.registerControl('test-repeat', mountFn);
    document.body.innerHTML = '<div id="root" data-control="test-repeat"></div>';

    dispatchHtmxLoad(document.body);
    dispatchHtmxLoad(document.body);

    // Reproduces settings/market/_refresh_area.html.twig's hx-trigger="load delay:2s" pattern
    // (the PR description's own worked example): the region keeps re-triggering htmx:load on the
    // very same element without it ever leaving the document.
    expect(mountFn).toHaveBeenCalledTimes(1);
});

test('an exception thrown by one control does not stop the next control on the page from mounting', () => {
    const failingMount = jest.fn(() => {
        throw new Error('boom');
    });
    const okMount = jest.fn();
    window.Controller.registerControl('test-failing', failingMount);
    window.Controller.registerControl('test-ok', okMount);
    document.body.innerHTML = `
        <div data-control="test-failing"></div>
        <div data-control="test-ok"></div>
    `;
    jest.spyOn(console, 'error').mockImplementation(() => {});

    dispatchHtmxLoad(document.body);

    expect(failingMount).toHaveBeenCalledTimes(1);
    expect(okMount).toHaveBeenCalledTimes(1);
});

test('an unknown control name logs to the console and renders a visible in-page notice', async () => {
    window.AppTranslations = { trans: jest.fn((key, params) => Promise.resolve(`${key}:${params.name}`)) };
    jest.spyOn(console, 'error').mockImplementation(() => {});
    document.body.innerHTML = '<div id="root" data-control="test-does-not-exist"></div>';

    dispatchHtmxLoad(document.body);
    await Promise.resolve();
    await Promise.resolve();

    expect(console.error).toHaveBeenCalled();
    const root = document.getElementById('root');
    const notice = root.querySelector('.alert-danger');
    expect(notice).not.toBeNull();
    expect(notice.textContent).toBe('controller.unknown_control_text:test-does-not-exist');
});

test('unmounting via htmx:beforeCleanupElement calls the cleanup function mountFn returned, exactly once', () => {
    const unmount = jest.fn();
    window.Controller.registerControl('test-unmount', () => unmount);
    document.body.innerHTML = '<div id="root" data-control="test-unmount"></div>';
    const root = document.getElementById('root');

    dispatchHtmxLoad(root);
    dispatchBeforeCleanup(root);

    expect(unmount).toHaveBeenCalledTimes(1);
});

test('a control that returns nothing from mountFn does not error out on unmount', () => {
    window.Controller.registerControl('test-no-cleanup', () => {});
    document.body.innerHTML = '<div id="root" data-control="test-no-cleanup"></div>';
    const root = document.getElementById('root');

    dispatchHtmxLoad(root);

    expect(() => dispatchBeforeCleanup(root)).not.toThrow();
});

test('a fresh element with the same control name mounts again after the previous one was cleaned up', () => {
    const mountFn = jest.fn();
    window.Controller.registerControl('test-remount', mountFn);
    document.body.innerHTML = '<div id="root" data-control="test-remount"></div>';
    const firstRoot = document.getElementById('root');

    dispatchHtmxLoad(firstRoot);
    dispatchBeforeCleanup(firstRoot);

    firstRoot.remove();
    document.body.innerHTML = '<div id="root" data-control="test-remount"></div>';
    dispatchHtmxLoad(document.getElementById('root'));

    expect(mountFn).toHaveBeenCalledTimes(2);
});
