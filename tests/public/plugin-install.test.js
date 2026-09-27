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

// Loads the real plugin-install.js against a DOM shaped like settings/plugins/index.html.twig's
// install form (issue #251). The gate this module enforces is the point of the module: the submit
// button must stay disabled until the user has both picked a .zip file and clicked the form's own
// confirm button, and picking a different file afterwards must re-lock it rather than leaving a
// stale confirmation in effect for a swapped-in archive.
//
// controller.js is required once at file scope, not inside loadPluginInstallModule() — see
// controller.test.js for why a fresh require() per test would leak document-level listeners.
require('../../app/assets/js/controller.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function setUpDom() {
    document.body.innerHTML = `
        <form id="plugin-install-form" data-control="plugin-install">
            <input type="hidden" name="_token" value="csrf-token">
            <input type="file" id="plugin-install-file">
            <p id="plugin-install-client-error" hidden></p>
            <div id="plugin-install-warning" hidden>
                <button type="button" id="plugin-install-confirm-button"></button>
            </div>
            <button type="submit" id="plugin-install-submit-button" disabled></button>
        </form>
    `;
}

// jsdom's file input rejects a direct assignment to .files, so the property is redefined instead
// — the module only ever reads fileInput.files[0].name, so a plain object stands in for a real
// File.
function selectFile(name) {
    const input = document.getElementById('plugin-install-file');
    Object.defineProperty(input, 'files', {
        value:        name === null ? [] : [{ name }],
        configurable: true,
    });
    input.dispatchEvent(new Event('change'));
}

function mockTranslations() {
    window.AppTranslations = { trans: jest.fn((key) => Promise.resolve(key)) };
}

function loadPluginInstallModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/plugin-install.js');
    });
    mountControls();
}

async function flushMicrotasks() {
    for (let i = 0; i < 10; i += 1) {
        await Promise.resolve();
    }
}

beforeEach(() => {
    jest.resetModules();
    setUpDom();
    mockTranslations();
});

afterEach(() => {
    delete window.AppTranslations;
});

test('submit stays disabled on mount, before any file is picked', () => {
    // The fixture markup already has the disabled attribute; flip it off first so the assertion
    // below actually proves mountPluginInstall() enforces the gate, rather than passing on the
    // fixture's own static state.
    document.getElementById('plugin-install-submit-button').disabled = false;

    loadPluginInstallModule();

    expect(document.getElementById('plugin-install-submit-button').disabled).toBe(true);
});

test('picking no file shows a client error instead of the warning', async () => {
    loadPluginInstallModule();

    selectFile(null);
    await flushMicrotasks();

    const clientError = document.getElementById('plugin-install-client-error');
    expect(clientError.hidden).toBe(false);
    expect(clientError.textContent).toBe('settings_plugins.install_error_no_file');
    expect(document.getElementById('plugin-install-warning').hidden).toBe(true);
    expect(document.getElementById('plugin-install-submit-button').disabled).toBe(true);
});

test('picking a .zip file shows the third-party warning but keeps submit disabled', async () => {
    loadPluginInstallModule();

    selectFile('plugin.zip');
    await flushMicrotasks();

    expect(document.getElementById('plugin-install-warning').hidden).toBe(false);
    expect(document.getElementById('plugin-install-client-error').hidden).toBe(true);
    expect(document.getElementById('plugin-install-submit-button').disabled).toBe(true);
});

test('picking a non-zip file shows a client error instead of the warning', async () => {
    loadPluginInstallModule();

    selectFile('plugin.exe');
    await flushMicrotasks();

    const clientError = document.getElementById('plugin-install-client-error');
    expect(clientError.hidden).toBe(false);
    expect(clientError.textContent).toBe('settings_plugins.install_error_not_zip');
    expect(document.getElementById('plugin-install-warning').hidden).toBe(true);
    expect(document.getElementById('plugin-install-submit-button').disabled).toBe(true);
});

test('clicking the confirm button enables submit', async () => {
    loadPluginInstallModule();

    selectFile('plugin.zip');
    await flushMicrotasks();
    document.getElementById('plugin-install-confirm-button').click();

    expect(document.getElementById('plugin-install-submit-button').disabled).toBe(false);
});

// The acceptance criterion the issue calls out by name: swapping the file after confirming must
// not leave the already-confirmed gate open for the new archive.
test('picking a different file after confirming resets the gate back to disabled', async () => {
    loadPluginInstallModule();

    selectFile('plugin.zip');
    await flushMicrotasks();
    document.getElementById('plugin-install-confirm-button').click();
    expect(document.getElementById('plugin-install-submit-button').disabled).toBe(false);

    selectFile('another.zip');
    await flushMicrotasks();

    expect(document.getElementById('plugin-install-submit-button').disabled).toBe(true);
    expect(document.getElementById('plugin-install-warning').hidden).toBe(false);
});

test('picking a non-zip file after confirming also resets the gate, showing the client error', async () => {
    loadPluginInstallModule();

    selectFile('plugin.zip');
    await flushMicrotasks();
    document.getElementById('plugin-install-confirm-button').click();

    selectFile('plugin.exe');
    await flushMicrotasks();

    expect(document.getElementById('plugin-install-submit-button').disabled).toBe(true);
    expect(document.getElementById('plugin-install-warning').hidden).toBe(true);
    expect(document.getElementById('plugin-install-client-error').hidden).toBe(false);
});
