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

// Loads the real storage-scan.js against a DOM shaped like storage/list.html.twig's #storage-scan
// section, with window.ScanWatcher.watch replaced by a spy that captures the callbacks the module
// registers. The module is a self-invoking browser script with no exports, so its onProgress/
// onDone/onFailed handlers are driven directly through that captured object, the same way scan.js
// would drive them from a real WebSocket message.
//
// controller.js is required once at file scope, not inside loadStorageScanModule() — see
// controller.test.js for why a fresh require() per test would leak document-level listeners.
require('../../app/assets/js/controller.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function unmountControls(root) {
    root.dispatchEvent(new CustomEvent('htmx:beforeCleanupElement', { bubbles: true, detail: { elt: root } }));
}

function setUpDom() {
    document.body.innerHTML = `
        <section id="storage-scan"
                 data-control="storage-scan"
                 data-storage-id="42"
                 data-confirm-url="/storage/42/scan/confirm"
                 data-confirm-token="csrf-token"
                 data-anime-new-url="/anime/new">
            <div id="storage-scan-progress">
                <div class="progress">
                    <div class="progress-bar" id="storage-scan-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <span id="storage-scan-progress-text"></span>
            </div>
            <p id="storage-scan-error" hidden></p>
            <div id="storage-scan-results" hidden></div>
        </section>
    `;
}

function mockScanWatcher() {
    const watchers = {};

    window.ScanWatcher = {
        watch: jest.fn((storageId, callbacks) => {
            watchers[storageId] = callbacks;
        }),
        unwatch: jest.fn((storageId) => {
            delete watchers[storageId];
        }),
    };

    return watchers;
}

// trans() falls back to the key itself when a catalogue lookup misses (translations.js) — reusing
// that behaviour here means assertions can check against the key directly instead of maintaining
// a parallel copy of the ru/en dictionary.
function mockTranslations() {
    window.AppTranslations = { trans: jest.fn((key) => Promise.resolve(key)) };
}

function jsonResponse(body) {
    return { ok: true, status: 200, json: () => Promise.resolve(body) };
}

function loadStorageScanModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/storage-scan.js');
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
    jest.useFakeTimers();
    setUpDom();
});

afterEach(() => {
    jest.useRealTimers();
    delete global.fetch;
    delete window.AppTranslations;
    delete window.ScanWatcher;
    window.history.replaceState(null, '', '/');
});

test('progress updates set the bar width via style, not the removed native value attribute', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    loadStorageScanModule();

    await watchers['42'].onProgress({ percent: 42, processed: 5, total: 12 });
    await flushMicrotasks();

    const bar = document.getElementById('storage-scan-progress-bar');
    expect(bar.style.width).toBe('42%');
    expect(bar.getAttribute('aria-valuenow')).toBe('42');
    expect(bar.hasAttribute('value')).toBe(false);
    expect(document.getElementById('storage-scan-progress-text').textContent).not.toBe('');
});

test('finished groups render as Bootstrap list groups', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    loadStorageScanModule();

    await watchers['42'].onDone({
        items: [
            { type: 'Updated', storage_path: '/a', anime: { title: 'Steins;Gate' } },
            { type: 'AutoLinked', storage_path: '/b', anime: { title: 'Mushishi' } },
        ],
    });
    await flushMicrotasks();

    const resultsBox = document.getElementById('storage-scan-results');
    expect(resultsBox.hidden).toBe(false);

    const groups = resultsBox.querySelectorAll('section.storage-scan__group');
    expect(groups).toHaveLength(2);
    groups.forEach((group) => {
        expect(group.classList.contains('mb-4')).toBe(true);
        expect(group.querySelector('h3').classList.contains('h6')).toBe(true);
        expect(group.querySelector('ul').classList.contains('list-group')).toBe(true);
    });

    const items = resultsBox.querySelectorAll('li');
    expect(items).toHaveLength(2);
    items.forEach((li) => expect(li.classList.contains('list-group-item')).toBe(true));
});

test('a candidate needing confirmation renders Bootstrap form-check radios and a primary button, and confirming posts to the confirm endpoint', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ anime: { title: 'Steins;Gate' } })));
    loadStorageScanModule();

    await watchers['42'].onDone({
        items: [{
            type:            'NeedsConfirmation',
            storage_path:    '/anime/steins-gate',
            cleaned_name:    'Steins Gate',
            candidates:      [
                { anime_id: 7, title: 'Steins;Gate' },
                { anime_id: null, title: 'Steins;Gate 0' },
            ],
        }],
    });
    await flushMicrotasks();

    const li = document.querySelector('#storage-scan-results li');
    const checks = li.querySelectorAll('.form-check');
    expect(checks).toHaveLength(2);

    const radio = li.querySelector('.form-check-input');
    const label = li.querySelector('.form-check-label');
    expect(radio.type).toBe('radio');
    expect(label.htmlFor).toBe(radio.id);

    const button = li.querySelector('button');
    expect(button.className).toBe('btn btn-primary mt-2');

    button.click();
    await flushMicrotasks();

    expect(button.disabled).toBe(true);
    expect(global.fetch).toHaveBeenCalledWith('/storage/42/scan/confirm', expect.objectContaining({
        method: 'POST',
        body:   JSON.stringify({ token: 'csrf-token', storage_path: '/anime/steins-gate', anime_id: 7 }),
    }));
    expect(li.textContent).toBe('storage_list.confirmed_text');
});

test('a confirmation card shows the folder, the differing cleaned name, each candidate source and a prefilled "none match" link, with the first candidate preselected', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    loadStorageScanModule();

    await watchers['42'].onDone({
        items: [{
            type:         'NeedsConfirmation',
            storage_path: 'Steins Gate (2011) 1080p',
            cleaned_name: 'Steins Gate',
            candidates:   [
                { anime_id: 7, plugin_id: null, plugin_name: null, title: 'Steins;Gate' },
                { anime_id: null, plugin_id: 'acme', plugin_name: 'Acme List', external_id: '1', title: 'Steins;Gate 0' },
                { anime_id: null, plugin_id: 'bare', plugin_name: null, external_id: '2', title: 'Steins;Gate Movie' },
            ],
        }],
    });
    await flushMicrotasks();

    const li = document.querySelector('#storage-scan-results li');
    expect(li.querySelector('p').textContent).toBe('Steins Gate (2011) 1080p');
    expect(window.AppTranslations.trans).toHaveBeenCalledWith('storage_list.cleaned_name_text', { name: 'Steins Gate' });

    const labels = li.querySelectorAll('.form-check-label');
    expect(window.AppTranslations.trans).toHaveBeenCalledWith('storage_list.candidate_source_catalog');
    expect(window.AppTranslations.trans).toHaveBeenCalledWith('storage_list.candidate_label', { title: 'Steins;Gate', source: 'storage_list.candidate_source_catalog' });
    expect(window.AppTranslations.trans).toHaveBeenCalledWith('storage_list.candidate_label', { title: 'Steins;Gate 0', source: 'Acme List' });
    expect(window.AppTranslations.trans).toHaveBeenCalledWith('storage_list.candidate_label', { title: 'Steins;Gate Movie', source: 'bare' });
    expect(labels).toHaveLength(3);
    expect(Array.from(labels).map((l) => l.textContent)).toEqual(Array(3).fill('storage_list.candidate_label'));
    expect(li.querySelector('p.text-body-secondary').textContent).toBe('storage_list.cleaned_name_text');

    const radios = li.querySelectorAll('.form-check-input');
    expect(Array.from(radios).map((radio) => radio.checked)).toEqual([true, false, false]);

    const link = li.querySelector('a');
    expect(link.textContent).toBe('storage_list.none_match_link');
    expect(link.getAttribute('href')).toBe('/anime/new?title=Steins+Gate&storage_id=42&storage_path=Steins+Gate+%282011%29+1080p');
});

test('a confirmation card omits the cleaned name when it equals the folder', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    loadStorageScanModule();

    await watchers['42'].onDone({
        items: [{
            type:         'NeedsConfirmation',
            storage_path: 'Trigun',
            cleaned_name: 'Trigun',
            candidates:   [{ anime_id: 1, title: 'Trigun' }],
        }],
    });
    await flushMicrotasks();

    expect(window.AppTranslations.trans).not.toHaveBeenCalledWith('storage_list.cleaned_name_text', expect.anything());

    const li = document.querySelector('#storage-scan-results li');
    expect(li.querySelector('p').textContent).toBe('Trigun');
    expect(li.querySelector('.form-check-input')).not.toBeNull();
    expect(li.querySelector('p.text-body-secondary')).toBeNull();
});

test('confirming a plugin candidate posts its real pluginId/externalId instead of a bare name', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ anime: { title: 'Trigun' }, filled_from_plugin: true })));
    loadStorageScanModule();

    await watchers['42'].onDone({
        items: [{
            type:         'NeedsConfirmation',
            storage_path: '/anime/trigun',
            cleaned_name: 'Trigun',
            candidates:   [{ anime_id: null, plugin_id: 'animedb-shikimori', external_id: '104', title: 'Trigun' }],
        }],
    });
    await flushMicrotasks();

    const li = document.querySelector('#storage-scan-results li');
    li.querySelector('button').click();
    await flushMicrotasks();

    expect(global.fetch).toHaveBeenCalledWith('/storage/42/scan/confirm', expect.objectContaining({
        method: 'POST',
        body:   JSON.stringify({
            token:        'csrf-token',
            storage_path: '/anime/trigun',
            plugin_id:    'animedb-shikimori',
            external_id:  '104',
            name:         'Trigun',
        }),
    }));
    expect(li.textContent).toBe('storage_list.confirmed_text');
});

test('a confirmation response with filled_from_plugin:false shows the without-plugin-data message', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ anime: { title: 'Trigun' }, filled_from_plugin: false })));
    loadStorageScanModule();

    await watchers['42'].onDone({
        items: [{
            type:         'NeedsConfirmation',
            storage_path: '/anime/trigun',
            cleaned_name: 'Trigun',
            candidates:   [{ anime_id: null, plugin_id: 'animedb-shikimori', external_id: '104', title: 'Trigun' }],
        }],
    });
    await flushMicrotasks();

    const li = document.querySelector('#storage-scan-results li');
    li.querySelector('button').click();
    await flushMicrotasks();

    expect(li.textContent).toBe('storage_list.confirmed_without_plugin_data_text');
});

test('a 409 conflict response renders the conflict message instead of the generic error alert', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    global.fetch = jest.fn(() => Promise.resolve({
        ok:     false,
        status: 409,
        json:   () => Promise.resolve({ conflict: { anime: { id: 7, title: 'Trigun' }, storage_path: 'Trigun (old).mkv' } }),
    }));
    loadStorageScanModule();

    await watchers['42'].onDone({
        items: [{
            type:         'NeedsConfirmation',
            storage_path: '/anime/trigun',
            cleaned_name: 'Trigun',
            candidates:   [{ anime_id: null, plugin_id: 'animedb-shikimori', external_id: '104', title: 'Trigun' }],
        }],
    });
    await flushMicrotasks();

    const li = document.querySelector('#storage-scan-results li');
    li.querySelector('button').click();
    await flushMicrotasks();

    expect(li.textContent).toBe('storage_list.conflict_text');
    expect(li.querySelector('.alert')).toBeNull();
});

test('Conflict and Error scan items render instead of being silently dropped', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    loadStorageScanModule();

    await watchers['42'].onDone({
        items: [
            { type: 'Conflict', storage_path: '/a', anime: { title: 'Trigun' }, already_linked_storage_path: '/old/Trigun.mkv' },
            { type: 'Error', storage_path: '/b', cleaned_name: 'Bleach', error_message: 'search backend unavailable' },
        ],
    });
    await flushMicrotasks();

    const resultsBox = document.getElementById('storage-scan-results');
    const groups = resultsBox.querySelectorAll('section.storage-scan__group');
    expect(groups).toHaveLength(2);

    const items = resultsBox.querySelectorAll('li');
    expect(items).toHaveLength(2);
    items.forEach((li) => expect(li.textContent.length).toBeGreaterThan(0));
});

test('a failed confirmation renders a Bootstrap alert instead of the plain error text it used before', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    global.fetch = jest.fn(() => Promise.resolve({ ok: false, status: 500 }));
    loadStorageScanModule();

    await watchers['42'].onDone({
        items: [{
            type:         'NeedsConfirmation',
            storage_path: '/anime/steins-gate',
            cleaned_name: 'Steins Gate',
            candidates:   [{ anime_id: 7, title: 'Steins;Gate' }],
        }],
    });
    await flushMicrotasks();

    const li = document.querySelector('#storage-scan-results li');
    li.querySelector('button').click();
    await flushMicrotasks();

    const error = li.querySelector('.alert');
    expect(error).not.toBeNull();
    expect(error.className).toBe('alert alert-danger mt-2');
    expect(li.querySelector('button').disabled).toBe(false);
});

test('an empty scan result renders a muted message instead of an empty results box', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    loadStorageScanModule();

    await watchers['42'].onDone({ items: [] });
    await flushMicrotasks();

    const resultsBox = document.getElementById('storage-scan-results');
    const empty = resultsBox.querySelector('p');
    expect(empty.classList.contains('text-muted')).toBe(true);
});

// Drives storage-scan.js against the real translations.js (issue #677) instead of the trans()
// stub used above: bidi isolation is applied to each param value before it reaches the shared
// resolveKey() substitution, so a placeholder repeated twice in one string must come out wrapped
// and substituted identically both times, not just on the first occurrence.
test('a value substituted into a message is bidi-isolated at every occurrence of a repeated placeholder', async () => {
    const watchers = mockScanWatcher();
    document.documentElement.lang = 'ru';
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ 'storage_list.auto_linked_text': '%title% / %title%' })));
    jest.isolateModules(() => {
        require('../../app/assets/js/translations.js');
    });
    loadStorageScanModule();

    await watchers['42'].onDone({
        items: [{ type: 'AutoLinked', storage_path: '/b', anime: { title: 'Mushishi' } }],
    });
    await flushMicrotasks();

    const li = document.querySelector('#storage-scan-results li');
    const isolated = '⁨Mushishi⁩';
    expect(li.textContent).toBe(`${isolated} / ${isolated}`);
});

// Issue #734 acceptance criterion: a node replaced by an htmx swap must not leave a live timer or
// subscription behind. storage-scan.js has both — the 15s no-response timeout and the
// window.ScanWatcher subscription — so this drives both across an htmx:beforeCleanupElement on
// the control's own root and proves neither survives it.
test('unmounting clears the no-response timer and drops the ScanWatcher subscription', async () => {
    const watchers = mockScanWatcher();
    mockTranslations();
    loadStorageScanModule();

    expect(window.ScanWatcher.watch).toHaveBeenCalledWith('42', expect.anything());
    expect(watchers['42']).toBeDefined();

    unmountControls(document.getElementById('storage-scan'));

    expect(window.ScanWatcher.unwatch).toHaveBeenCalledWith('42');
    expect(watchers['42']).toBeUndefined();

    // The no-response timer would otherwise fire at the 15s mark and write into the now-detached
    // progress/error boxes — advancing fake timers past it must not throw and must not touch them.
    const errorBox = document.getElementById('storage-scan-error');
    jest.advanceTimersByTime(15000);
    await flushMicrotasks();

    expect(errorBox.hidden).toBe(true);
    expect(window.AppTranslations.trans).not.toHaveBeenCalledWith('storage_list.scan_no_response');
});

// Issue #834 review: the server now decides whether a scan is running from its own job lock, not
// from this page's `?started=1` query string, so that parameter must not linger in the address
// bar after mount — an F5/back-forward hitting the stale URL would otherwise still look like a
// freshly-triggered scan to a human reading the URL, even though the server itself no longer
// reads it.
test('mounting strips the started query parameter from the address bar', () => {
    window.history.pushState(null, '', '/storage/42/scan-progress?started=1');
    mockScanWatcher();
    mockTranslations();

    loadStorageScanModule();

    expect(window.location.search).toBe('');
    expect(window.location.pathname).toBe('/storage/42/scan-progress');
});
