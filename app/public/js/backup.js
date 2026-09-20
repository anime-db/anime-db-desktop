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

// Wires settings/backup/index.html.twig to window.animeDb.catalogExportStart()/catalogExportCancel()
// (native/catalog-export/index.js, issue #657). Unlike scan.js's window.ScanWatcher, this opens
// its own WebSocket rather than sharing a keyed-by-storage-id connection: a catalog export is a
// single global operation, not one of several concurrent per-storage jobs, so there is nothing to
// key its export.progress/export.done/export.failed events by.
(function () {
    const form = document.getElementById('settings-backup-form');
    if (!form) {
        return;
    }

    const unavailable = document.getElementById('settings-backup-unavailable');
    const pathInput = document.getElementById('settings-backup-path');
    const pickButton = document.getElementById('settings-backup-pick-folder');
    const startButton = document.getElementById('settings-backup-start');
    const cancelButton = document.getElementById('settings-backup-cancel');
    const progressBox = document.getElementById('settings-backup-progress');
    const progressBar = document.getElementById('settings-backup-progress-bar');
    const progressText = document.getElementById('settings-backup-progress-text');
    const resultBox = document.getElementById('settings-backup-result');
    const errorBox = document.getElementById('settings-backup-error');

    // The export only runs through Electron's native layer (window.animeDb, exposed by
    // native/window/preload.js) — a plain browser tab has nowhere to spawn app:catalog:export.
    if (!window.animeDb || !window.animeDb.catalogExportStart) {
        form.hidden = true;
        unavailable.hidden = false;
        return;
    }

    // The database snapshot is a single indivisible step, so it is given a small fixed slice of
    // the bar rather than a proportional one — otherwise a catalog with very few media files
    // would show the bar jumping straight from ~0% to ~100% on the media phase alone.
    const DATABASE_PHASE_WEIGHT = 0.1;

    let cancelRequested = false;
    let socket = null;

    function connect() {
        socket = new WebSocket(`ws://${window.location.host}/ws`);
        socket.onmessage = dispatch;
        socket.onclose = () => { socket = null; };
        socket.onerror = () => {};
    }

    function dispatch(message) {
        let payload;
        try {
            payload = JSON.parse(message.data);
        } catch {
            return;
        }

        switch (payload.event) {
            case 'export.progress':
                onProgress(payload.data);
                break;
            case 'export.done':
                onDone(payload.data);
                break;
            case 'export.failed':
                onFailed(payload.data);
                break;
            default:
                break;
        }
    }

    function setPercent(percent) {
        progressBar.style.width = `${percent}%`;
        progressBar.setAttribute('aria-valuenow', String(percent));
    }

    function resetControls() {
        progressBox.hidden = true;
        cancelButton.hidden = true;
        startButton.disabled = false;
        setPercent(0);
    }

    async function onProgress(data) {
        progressBox.hidden = false;
        const total = data.total > 0 ? data.total : 1;
        const ratio = Math.min(data.current / total, 1);
        const percent = data.phase === 'database'
            ? Math.round(ratio * DATABASE_PHASE_WEIGHT * 100)
            : Math.round((DATABASE_PHASE_WEIGHT + ratio * (1 - DATABASE_PHASE_WEIGHT)) * 100);
        setPercent(percent);

        const key = data.phase === 'database' ? 'settings_backup.progress_database' : 'settings_backup.progress_media';
        progressText.textContent = await window.AppTranslations.trans(key, {
            current: data.current,
            total: data.total,
        });
    }

    async function onDone(data) {
        resetControls();
        resultBox.hidden = false;
        resultBox.textContent = await window.AppTranslations.trans('settings_backup.done_text', { path: data.path });
    }

    async function onFailed(data) {
        resetControls();

        if (cancelRequested) {
            cancelRequested = false;
            resultBox.hidden = false;
            resultBox.textContent = await window.AppTranslations.trans('settings_backup.cancelled_text');
            return;
        }

        errorBox.hidden = false;
        errorBox.textContent = data.reason === 'insufficient_space'
            ? await window.AppTranslations.trans('settings_backup.error_insufficient_space')
            : await window.AppTranslations.trans('settings_backup.error_generic');
    }

    pickButton.addEventListener('click', async () => {
        const folder = await window.animeDb.pickFolder();
        if (folder) {
            pathInput.value = folder;
            startButton.disabled = false;
        }
    });

    startButton.addEventListener('click', async () => {
        if (!pathInput.value) {
            return;
        }

        resultBox.hidden = true;
        errorBox.hidden = true;
        progressBox.hidden = false;
        setPercent(0);
        startButton.disabled = true;
        cancelButton.hidden = false;
        cancelRequested = false;

        if (socket === null) {
            connect();
        }

        // export.failed already carries the reason in the common case (see onFailed above) —
        // this branch only covers outcomes that never reach WsPublisher at all: the process never
        // even starting (e.g. it could not spawn), and a cancel, since killing the process by PID
        // (see cancelButton below) bypasses export()'s catch block entirely and so never publishes
        // export.failed either.
        const outcome = await window.animeDb.catalogExportStart(pathInput.value);
        if (cancelRequested) {
            cancelRequested = false;
            resetControls();
            resultBox.hidden = false;
            resultBox.textContent = await window.AppTranslations.trans('settings_backup.cancelled_text');
        } else if (!outcome.ok) {
            resetControls();
            errorBox.hidden = false;
            errorBox.textContent = await window.AppTranslations.trans('settings_backup.error_generic');
        }
    });

    cancelButton.addEventListener('click', () => {
        cancelRequested = true;
        cancelButton.disabled = true;
        window.animeDb.catalogExportCancel().finally(() => {
            cancelButton.disabled = false;
        });
    });
})();
