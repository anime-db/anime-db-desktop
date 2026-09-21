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

// Loads the real backup.js against a DOM shaped like settings/backup/index.html.twig, with the
// export WebSocket replaced by a spy class. backup.js used to run substituted param values through
// its own private format() before this refactor (issue #677) — these tests drive it through the
// real translations.js instead, to prove it now delegates %name% substitution to trans() alone,
// including for a placeholder repeated twice in a single catalogue string.

function setUpDom() {
    document.body.innerHTML = `
        <p id="settings-backup-unavailable" hidden></p>
        <section id="settings-backup-export-section">
            <div id="settings-backup-form">
                <input type="text" id="settings-backup-path">
                <button type="button" id="settings-backup-pick-folder"></button>
                <button type="button" id="settings-backup-start"></button>
                <button type="button" id="settings-backup-cancel" hidden></button>
                <div id="settings-backup-progress" hidden>
                    <div class="progress-bar" id="settings-backup-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    <span id="settings-backup-progress-text"></span>
                </div>
                <p id="settings-backup-result" hidden></p>
                <p id="settings-backup-error" hidden></p>
            </div>
        </section>
        <section id="settings-backup-import-section">
            <div id="settings-import-form">
                <input type="text" id="settings-import-path">
                <button type="button" id="settings-import-pick-file"></button>
                <button type="button" id="settings-import-start"></button>
                <div id="settings-import-progress" hidden>
                    <div class="progress-bar" id="settings-import-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    <span id="settings-import-progress-text"></span>
                </div>
                <p id="settings-import-result" hidden></p>
                <p id="settings-import-error" hidden></p>
            </div>
        </section>
        <section id="settings-backup-snapshots-section">
            <p id="settings-backup-snapshots-error" hidden></p>
            <ul id="settings-backup-snapshots-list">
                <li>
                    <button type="button" class="settings-backup-restore-button" data-backup-name="data-preimport-20260101-000000.db"></button>
                </li>
            </ul>
        </section>
    `;
}

class MockWebSocket {
    constructor(url) {
        this.url = url;
        MockWebSocket.instances.push(this);
    }

    close() {}
}
MockWebSocket.instances = [];

function jsonResponse(body) {
    return { ok: true, status: 200, json: () => Promise.resolve(body) };
}

function loadBackupModule() {
    jest.isolateModules(() => {
        require('../../app/public/js/backup.js');
    });
}

async function flushMicrotasks() {
    for (let i = 0; i < 10; i += 1) {
        await Promise.resolve();
    }
}

beforeEach(() => {
    jest.resetModules();
    setUpDom();
    MockWebSocket.instances = [];
    global.WebSocket = MockWebSocket;
    window.animeDb = {
        catalogExportStart: jest.fn(() => new Promise(() => {})),
        catalogExportCancel: jest.fn(),
        catalogImportStart: jest.fn(() => new Promise(() => {})),
        backupRestoreStart: jest.fn(() => new Promise(() => {})),
    };
    document.documentElement.lang = 'ru';
});

afterEach(() => {
    delete global.fetch;
    delete global.WebSocket;
    delete window.animeDb;
    delete window.AppTranslations;
});

test('a value substituted twice into a message via export.done comes out identical at both occurrences, with no format() left in backup.js', async () => {
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ 'settings_backup.done_text': '%path% (%path%)' })));
    jest.isolateModules(() => {
        require('../../app/public/js/translations.js');
    });
    loadBackupModule();

    document.getElementById('settings-backup-path').value = '/backups/catalog.zip';
    document.getElementById('settings-backup-start').click();
    await flushMicrotasks();

    expect(MockWebSocket.instances).toHaveLength(1);
    MockWebSocket.instances[0].onmessage({
        data: JSON.stringify({ event: 'export.done', data: { path: '/backups/catalog.zip' } }),
    });
    await flushMicrotasks();

    const resultBox = document.getElementById('settings-backup-result');
    expect(resultBox.hidden).toBe(false);
    // resolveKey() (translations.js) bidi-isolates every substituted value, including the backup
    // path — a user-chosen filesystem path is exactly the kind of open value the isolation
    // protects (issue #677, issue #450) — repeated at every occurrence of the placeholder.
    const isolatedPath = '⁨/backups/catalog.zip⁩';
    expect(resultBox.textContent).toBe(`${isolatedPath} (${isolatedPath})`);
});

test('export progress substitutes both progress placeholders via trans(), not a leftover local format()', async () => {
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ 'settings_backup.progress_media': '%current% of %current%, total %total%' })));
    jest.isolateModules(() => {
        require('../../app/public/js/translations.js');
    });
    loadBackupModule();

    document.getElementById('settings-backup-path').value = '/backups/catalog.zip';
    document.getElementById('settings-backup-start').click();
    await flushMicrotasks();

    MockWebSocket.instances[0].onmessage({
        data: JSON.stringify({ event: 'export.progress', data: { phase: 'media', current: 3, total: 10 } }),
    });
    await flushMicrotasks();

    const progressText = document.getElementById('settings-backup-progress-text');
    expect(progressText.textContent).toBe('⁨3⁩ of ⁨3⁩, total ⁨10⁩');
});

test('clicking a restore button asks for confirmation naming the snapshot, then calls backupRestoreStart()', async () => {
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({
        'settings_backup.restore_confirm_text': 'Replace the current catalog with "%name%"?',
    })));
    jest.isolateModules(() => {
        require('../../app/public/js/translations.js');
    });
    window.confirm = jest.fn(() => true);
    window.animeDb.backupRestoreStart = jest.fn(() => new Promise(() => {}));
    loadBackupModule();
    await flushMicrotasks();

    document.querySelector('.settings-backup-restore-button').click();
    await flushMicrotasks();

    expect(window.confirm).toHaveBeenCalledWith('Replace the current catalog with "⁨data-preimport-20260101-000000.db⁩"?');
    expect(window.animeDb.backupRestoreStart).toHaveBeenCalledWith('data-preimport-20260101-000000.db');
});

test('declining the restore confirmation dialog never calls backupRestoreStart()', async () => {
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({})));
    jest.isolateModules(() => {
        require('../../app/public/js/translations.js');
    });
    window.confirm = jest.fn(() => false);
    window.animeDb.backupRestoreStart = jest.fn();
    loadBackupModule();
    await flushMicrotasks();

    document.querySelector('.settings-backup-restore-button').click();
    await flushMicrotasks();

    expect(window.animeDb.backupRestoreStart).not.toHaveBeenCalled();
});

test('shows an error and re-enables the button when the restore IPC call reports failure', async () => {
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({
        'settings_backup.restore_error_text': 'Failed to restore the snapshot.',
    })));
    jest.isolateModules(() => {
        require('../../app/public/js/translations.js');
    });
    window.confirm = jest.fn(() => true);
    window.animeDb.backupRestoreStart = jest.fn(() => Promise.resolve({ ok: false }));
    loadBackupModule();
    await flushMicrotasks();

    const button = document.querySelector('.settings-backup-restore-button');
    button.click();
    await flushMicrotasks();

    const errorBox = document.getElementById('settings-backup-snapshots-error');
    expect(errorBox.hidden).toBe(false);
    expect(errorBox.textContent).toBe('Failed to restore the snapshot.');
    expect(button.disabled).toBe(false);
});

// Issue #681 review: restoreBackup() in native/backup-restore/index.js runs after
// supervisor.stop() already tore down the PHP worker, so a failure there (disk full, file locked)
// rejects this IPC call instead of resolving { ok: false }. Without a catch, the button would stay
// disabled forever with no error shown and no relaunch.
test('shows an error and re-enables the button when the restore IPC call rejects', async () => {
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({
        'settings_backup.restore_error_text': 'Failed to restore the snapshot.',
    })));
    jest.isolateModules(() => {
        require('../../app/public/js/translations.js');
    });
    window.confirm = jest.fn(() => true);
    window.animeDb.backupRestoreStart = jest.fn(() => Promise.reject(new Error('copy failed')));
    loadBackupModule();
    await flushMicrotasks();

    const button = document.querySelector('.settings-backup-restore-button');
    button.click();
    await flushMicrotasks();

    const errorBox = document.getElementById('settings-backup-snapshots-error');
    expect(errorBox.hidden).toBe(false);
    expect(errorBox.textContent).toBe('Failed to restore the snapshot.');
    expect(button.disabled).toBe(false);
});
