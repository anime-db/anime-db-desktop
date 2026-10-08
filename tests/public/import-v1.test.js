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

// Loads the real import-v1.js against a DOM shaped like the onboarding card in
// anime/list.html.twig (issue #953), with the /ws socket replaced by a spy class and the
// translations catalogue by a small map.
require('../../app/assets/js/controller.js');

const MESSAGES = {
    'import_v1.report_created': 'created %count% (label %fromLabel%, default %byDefault%)',
    'import_v1.report_names': 'names %count% (ja %ja%, ru %ru%, none %none%)',
    'import_v1.report_related': 'related %sources% %descriptions% %studios% %labels%',
    'import_v1.report_genres': 'genres %count%',
    'import_v1.report_genres_dropped': 'dropped %count%',
    'import_v1.report_genres_unmapped': 'unmapped %count% (%names%)',
    'import_v1.report_covers': 'covers %imported%, missing %missing%',
    'import_v1.report_storages': 'storages %count%, unavailable %unavailable%',
    'import_v1.report_storages_skipped': 'skipped %names%',
    'import_v1.report_episodes_dropped': 'episodes dropped %count% (%titles%)',
    'import_v1.report_end_dates': 'end dates %count%',
    'import_v1.report_status_downgraded': 'downgraded %count%',
    'import_v1.report_needs_attention': 'attention %count%',
    'onboarding.import_v1_done_no_report': 'done, no report',
    'import_v1.error_not_v1_installation': 'not a v1 installation: %path%',
    'onboarding.import_v1_error_generic': 'generic failure',
    'onboarding.import_v1_progress_covers': 'covers %current%/%total%',
};

const FULL_REPORT = {
    animeCreated: 20, withStatusFromLabel: 12, withDefaultStatus: 8,
    namesJapanese: 5, namesRussian: 6, namesUnknownLocale: 7,
    sources: 3, descriptions: 4, studios: 2, labels: 1,
    genresMapped: 30, genresDroppedByDesign: 4, genresUnmapped: 2, unmappedGenreNames: ['Foo', 'Bar'],
    coversImported: 9, coversMissing: 11, storagesCreated: 2, storagesUnavailable: 1, skippedStorageNames: [],
    endDatesSynthesized: 0, durationsCleared: 0, episodesDroppedTitles: [], needsAttention: 0, statusesDowngraded: 0,
};

class MockWebSocket {
    constructor(url) {
        this.url = url;
        MockWebSocket.instances.push(this);
    }

    close() {}
}
MockWebSocket.instances = [];

function setUpDom() {
    document.body.innerHTML = `
        <main data-control="anime-list">
        <div id="onboarding-import-v1" data-control="onboarding-import-v1">
            <button type="button" id="onboarding-import-v1-pick"></button>
            <button type="button" id="onboarding-import-v1-cancel" hidden></button>
            <div id="onboarding-import-v1-progress" hidden>
                <div class="progress" role="progressbar" aria-valuenow="0">
                    <div class="progress-bar" id="onboarding-import-v1-progress-bar"></div>
                </div>
                <p id="onboarding-import-v1-progress-text"></p>
            </div>
            <p id="onboarding-import-v1-error" hidden></p>
            <p id="onboarding-import-v1-cancelled" hidden></p>
            <div id="onboarding-import-v1-result" hidden>
                <ul id="onboarding-import-v1-report"></ul>
                <button type="button" id="onboarding-import-v1-open"></button>
            </div>
        </div>
        </main>
    `;
}

async function flush() {
    for (let i = 0; i < 200; i += 1) {
        await Promise.resolve();
    }
}

function emit(event, data) {
    MockWebSocket.instances[0].onmessage({ data: JSON.stringify({ event, data }) });
}

async function startImport(startImpl = () => new Promise(() => {})) {
    window.animeDb.importV1Start = jest.fn(startImpl);
    document.getElementById('onboarding-import-v1-pick').click();
    await flush();
}

const text = (id) => document.getElementById(id).textContent;
const isolated = (value) => `⁨${value}⁩`;

beforeEach(() => {
    jest.resetModules();
    setUpDom();
    MockWebSocket.instances = [];
    global.WebSocket = MockWebSocket;
    global.fetch = jest.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(MESSAGES) }));
    window.animeDb = {
        pickFolder: jest.fn(() => Promise.resolve('/home/user/animedb-v1')),
        importV1Start: jest.fn(() => new Promise(() => {})),
        importV1Cancel: jest.fn(() => Promise.resolve()),
    };
    document.documentElement.lang = 'en';
    jest.isolateModules(() => {
        require('../../app/assets/js/translations.js');
        require('../../app/assets/js/import-v1.js');
    });
    document.body.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: document.body } }));
});

afterEach(() => {
    delete global.fetch;
    delete global.WebSocket;
    delete window.animeDb;
    delete window.AppTranslations;
});

test('picks the v1 folder with the existing pickFolder() and starts the import with it', async () => {
    await startImport();

    expect(window.animeDb.pickFolder).toHaveBeenCalledTimes(1);
    expect(window.animeDb.importV1Start).toHaveBeenCalledWith('/home/user/animedb-v1');
    expect(document.getElementById('onboarding-import-v1-cancel').hidden).toBe(false);
});

test('does nothing when the folder dialog is dismissed', async () => {
    window.animeDb.pickFolder = jest.fn(() => Promise.resolve(null));

    await startImport();

    expect(window.animeDb.importV1Start).not.toHaveBeenCalled();
});

test('hides the card outside the desktop shell', () => {
    delete window.animeDb;
    setUpDom();
    jest.isolateModules(() => {
        require('../../app/assets/js/import-v1.js');
    });
    document.body.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: document.body } }));

    expect(document.getElementById('onboarding-import-v1').hidden).toBe(true);
});

test('shows the phase counter and a bar that spans all three phases', async () => {
    await startImport();

    emit('import_v1.progress', { phase: 'covers', current: 5, total: 10 });
    await flush();

    expect(text('onboarding-import-v1-progress-text')).toBe(`covers ${isolated(5)}/${isolated(10)}`);
    // covers is the third of three phases: 2/3 done plus half of the last third.
    expect(document.getElementById('onboarding-import-v1-progress-bar').style.width).toBe('83%');
});

test('renders every field of the report, with 18+ genres and genres without a counterpart on separate lines', async () => {
    await startImport();

    emit('import_v1.done', FULL_REPORT);
    await flush();

    const lines = [...document.querySelectorAll('#onboarding-import-v1-report li')].map((li) => li.textContent);
    expect(document.getElementById('onboarding-import-v1-result').hidden).toBe(false);
    expect(lines).toEqual([
        `created ${isolated(20)} (label ${isolated(12)}, default ${isolated(8)})`,
        `names ${isolated(18)} (ja ${isolated(5)}, ru ${isolated(6)}, none ${isolated(7)})`,
        `related ${isolated(3)} ${isolated(4)} ${isolated(2)} ${isolated(1)}`,
        `genres ${isolated(30)}`,
        `dropped ${isolated(4)}`,
        `unmapped ${isolated(2)} (${isolated('Foo, Bar')})`,
        `covers ${isolated(9)}, missing ${isolated(11)}`,
        `storages ${isolated(2)}, unavailable ${isolated(1)}`,
    ]);
    expect(document.getElementById('onboarding-import-v1-cancel').hidden).toBe(true);
});

test('adds the conditional lines when their counters are non-zero', async () => {
    await startImport();

    emit('import_v1.done', {
        ...FULL_REPORT,
        episodesDroppedTitles: ['A', 'B'], endDatesSynthesized: 3, statusesDowngraded: 4, needsAttention: 5,
    });
    await flush();

    const lines = [...document.querySelectorAll('#onboarding-import-v1-report li')].map((li) => li.textContent);
    expect(lines.slice(8)).toEqual([
        `episodes dropped ${isolated(2)} (${isolated('A, B')})`,
        `end dates ${isolated(3)}`,
        `downgraded ${isolated(4)}`,
        `attention ${isolated(5)}`,
    ]);
});

test('adds the lines for skipped storages only when there are some', async () => {
    await startImport();

    emit('import_v1.done', { ...FULL_REPORT, skippedStorageNames: ['Old disk'] });
    await flush();

    const lines = [...document.querySelectorAll('#onboarding-import-v1-report li')].map((li) => li.textContent);
    expect(lines).toHaveLength(9);
    expect(lines[8]).toBe(`skipped ${isolated('Old disk')}`);
});

test('shows a validation refusal on the same screen, names the path, and lets the user pick again', async () => {
    await startImport();

    emit('import_v1.failed', { reason: 'not_v1_installation', params: { path: '/x/app/Resources/anime.db' } });
    await flush();

    const error = document.getElementById('onboarding-import-v1-error');
    expect(error.hidden).toBe(false);
    expect(error.textContent).toBe(`not a v1 installation: ${isolated('/x/app/Resources/anime.db')}`);
    expect(document.getElementById('onboarding-import-v1-result').hidden).toBe(true);
    expect(document.getElementById('onboarding-import-v1-pick').hidden).toBe(false);
    expect(document.getElementById('onboarding-import-v1-pick').disabled).toBe(false);
});

test('falls back to the generic message when the process fails without publishing anything', async () => {
    await startImport(() => Promise.resolve({ ok: false, code: 255 }));

    expect(text('onboarding-import-v1-error')).toBe('generic failure');
    expect(document.getElementById('onboarding-import-v1-pick').hidden).toBe(false);
});

test('cancel kills the process and returns the card to its initial state', async () => {
    let finish;
    await startImport(() => new Promise((resolve) => { finish = resolve; }));

    document.getElementById('onboarding-import-v1-cancel').click();
    finish({ ok: false, code: null });
    await flush();

    expect(window.animeDb.importV1Cancel).toHaveBeenCalledTimes(1);
    expect(document.getElementById('onboarding-import-v1-cancelled').hidden).toBe(false);
    expect(document.getElementById('onboarding-import-v1-error').hidden).toBe(true);
    expect(document.getElementById('onboarding-import-v1-pick').hidden).toBe(false);
    expect(document.getElementById('onboarding-import-v1-cancel').hidden).toBe(true);
});

test('a done event that arrives before a late cancel wins: the report stays, "cancelled" stays hidden', async () => {
    let finish;
    await startImport(() => new Promise((resolve) => { finish = resolve; }));

    document.getElementById('onboarding-import-v1-cancel').click();
    emit('import_v1.done', FULL_REPORT);
    await flush();
    finish({ ok: true, code: 0 });
    await flush();

    expect(document.getElementById('onboarding-import-v1-result').hidden).toBe(false);
    expect(document.getElementById('onboarding-import-v1-cancelled').hidden).toBe(true);
    expect(document.querySelectorAll('#onboarding-import-v1-report li').length).toBeGreaterThan(1);
});

test('shows a report-less message when the process succeeded but no done event arrived', async () => {
    jest.useFakeTimers();
    try {
        window.animeDb.importV1Start = jest.fn(() => Promise.resolve({ ok: true, code: 0 }));
        document.getElementById('onboarding-import-v1-pick').click();
        for (let i = 0; i < 70; i += 1) {
            await jest.advanceTimersByTimeAsync(50);
        }
        await flush();
    } finally {
        jest.useRealTimers();
    }

    const items = [...document.querySelectorAll('#onboarding-import-v1-report li')].map((li) => li.textContent);
    expect(document.getElementById('onboarding-import-v1-result').hidden).toBe(false);
    expect(items).toEqual(['done, no report']);
});

test('does not persist the report anywhere', async () => {
    const setItem = jest.spyOn(Storage.prototype, 'setItem');
    await startImport();

    emit('import_v1.done', FULL_REPORT);
    await flush();

    expect(setItem).not.toHaveBeenCalled();
    setItem.mockRestore();
});
