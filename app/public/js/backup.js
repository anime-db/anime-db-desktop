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

// Wires settings/backup/index.html.twig's export section to
// window.animeDb.catalogExportStart()/catalogExportCancel() (native/catalog-export/index.js,
// issue #657), its import section to window.animeDb.pickFile()/catalogImportStart()
// (native/dialog/index.js, native/catalog-import/index.js, issue #670), and its snapshot list's
// restore buttons to window.animeDb.backupRestoreStart() (native/backup-restore/index.js, issue
// #681). All three share a single WebSocket rather than each opening its own: unlike scan.js's
// window.ScanWatcher, a catalog export or import is a single global operation, not one of several
// concurrent per-storage jobs, so there is nothing to key export.*/import.* events by beyond the
// event name itself — and restore has no progress events of its own at all (see below).
(function () {
    const exportForm = document.getElementById('settings-backup-form');
    const importForm = document.getElementById('settings-import-form');
    if (!exportForm && !importForm) {
        return;
    }

    const unavailable = document.getElementById('settings-backup-unavailable');
    const exportSection = document.getElementById('settings-backup-export-section');
    const importSection = document.getElementById('settings-backup-import-section');
    const snapshotsSection = document.getElementById('settings-backup-snapshots-section');
    const snapshotsErrorBox = document.getElementById('settings-backup-snapshots-error');
    const restoreButtons = document.querySelectorAll('.settings-backup-restore-button');

    const pathInput = document.getElementById('settings-backup-path');
    const pickButton = document.getElementById('settings-backup-pick-folder');
    const startButton = document.getElementById('settings-backup-start');
    const cancelButton = document.getElementById('settings-backup-cancel');
    const progressBox = document.getElementById('settings-backup-progress');
    const progressBar = document.getElementById('settings-backup-progress-bar');
    const progressText = document.getElementById('settings-backup-progress-text');
    const resultBox = document.getElementById('settings-backup-result');
    const errorBox = document.getElementById('settings-backup-error');

    const importPathInput = document.getElementById('settings-import-path');
    const importPickButton = document.getElementById('settings-import-pick-file');
    const importStartButton = document.getElementById('settings-import-start');
    const importProgressBox = document.getElementById('settings-import-progress');
    const importProgressBar = document.getElementById('settings-import-progress-bar');
    const importProgressText = document.getElementById('settings-import-progress-text');
    const importResultBox = document.getElementById('settings-import-result');
    const importErrorBox = document.getElementById('settings-import-error');

    // All three sections only run through Electron's native layer (window.animeDb, exposed by
    // native/window/preload.js) — a plain browser tab has nowhere to spawn app:catalog:export,
    // app:catalog:stage, or the restore IPC handler.
    if (!window.animeDb || !window.animeDb.catalogExportStart || !window.animeDb.catalogImportStart
        || !window.animeDb.backupRestoreStart) {
        exportSection.hidden = true;
        importSection.hidden = true;
        if (snapshotsSection) snapshotsSection.hidden = true;
        unavailable.hidden = false;
        return;
    }

    // The database snapshot/restore is a single indivisible step, so it is given a small fixed
    // slice of the bar rather than a proportional one — otherwise a catalog with very few media
    // files would show the bar jumping straight from ~0% to ~100% on the media phase alone.
    const DATABASE_PHASE_WEIGHT = 0.1;

    // Maps CatalogStageCommand's EXIT_* constants back to a specific translated message (issue
    // #670 acceptance criterion 4) — the IPC outcome carries only the exit code, not the
    // exception's params, so unlike catalog_stage.error_* (used by the console output itself)
    // these keys take no placeholders beyond the archive path the page already knows.
    const IMPORT_ERROR_KEY_BY_CODE = {
        2: 'settings_backup.import_error_unreadable',
        3: 'settings_backup.import_error_missing_manifest',
        4: 'settings_backup.import_error_unsupported_format_version',
        5: 'settings_backup.import_error_missing_database',
        6: 'settings_backup.import_error_unsafe_entry',
    };

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
            case 'import.progress':
                onImportProgress(payload.data);
                break;
            default:
                break;
        }
    }

    function setPercent(bar, percent) {
        bar.style.width = `${percent}%`;
        bar.setAttribute('aria-valuenow', String(percent));
    }

    function phasePercent(data) {
        const total = data.total > 0 ? data.total : 1;
        const ratio = Math.min(data.current / total, 1);

        return data.phase === 'database'
            ? Math.round(ratio * DATABASE_PHASE_WEIGHT * 100)
            : Math.round((DATABASE_PHASE_WEIGHT + ratio * (1 - DATABASE_PHASE_WEIGHT)) * 100);
    }

    function resetControls() {
        progressBox.hidden = true;
        cancelButton.hidden = true;
        startButton.disabled = false;
        setPercent(progressBar, 0);
    }

    async function onProgress(data) {
        progressBox.hidden = false;
        setPercent(progressBar, phasePercent(data));

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

    async function onImportProgress(data) {
        importProgressBox.hidden = false;
        setPercent(importProgressBar, phasePercent(data));

        const key = data.phase === 'database' ? 'settings_backup.import_progress_database' : 'settings_backup.import_progress_media';
        importProgressText.textContent = await window.AppTranslations.trans(key, {
            current: data.current,
            total: data.total,
        });
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
        setPercent(progressBar, 0);
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

    importPickButton.addEventListener('click', async () => {
        const filterName = await window.AppTranslations.trans('settings_backup.import_file_filter_name');
        const filePath = await window.animeDb.pickFile([{ name: filterName, extensions: ['zip'] }]);
        if (filePath) {
            importPathInput.value = filePath;
            importStartButton.disabled = false;
        }
    });

    importStartButton.addEventListener('click', async () => {
        if (!importPathInput.value) {
            return;
        }

        const confirmMessage = await window.AppTranslations.trans('settings_backup.import_confirm_text', { path: importPathInput.value });
        if (!window.confirm(confirmMessage)) {
            return;
        }

        importResultBox.hidden = true;
        importErrorBox.hidden = true;
        importProgressBox.hidden = false;
        setPercent(importProgressBar, 0);
        importStartButton.disabled = true;

        if (socket === null) {
            connect();
        }

        // There is no import.failed WsPublisher event to carry a distinct reason (unlike
        // export.failed) — CatalogStageService only ever throws, it never publishes on failure —
        // so the specific reason (issue #670 acceptance criterion 4) comes from the exit code
        // this IPC call resolves with instead, via IMPORT_ERROR_KEY_BY_CODE.
        const outcome = await window.animeDb.catalogImportStart(importPathInput.value);
        importProgressBox.hidden = true;
        importStartButton.disabled = false;

        if (outcome.ok) {
            importResultBox.hidden = false;
            importResultBox.textContent = await window.AppTranslations.trans('settings_backup.import_done_text');
        } else {
            importErrorBox.hidden = false;
            const errorKey = IMPORT_ERROR_KEY_BY_CODE[outcome.code] || 'settings_backup.import_error_generic';
            importErrorBox.textContent = await window.AppTranslations.trans(errorKey, { path: importPathInput.value });
        }
    });

    // A successful restore relaunches the whole app from the main process (see
    // native/backup-restore/index.js) — there is nothing left here to update on success, only on
    // failure (e.g. the snapshot was removed from disk between the page load and the click).
    restoreButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            const name = button.dataset.backupName;
            const confirmMessage = await window.AppTranslations.trans('settings_backup.restore_confirm_text', { name });
            if (!window.confirm(confirmMessage)) {
                return;
            }

            snapshotsErrorBox.hidden = true;
            button.disabled = true;

            const outcome = await window.animeDb.backupRestoreStart(name);
            if (!outcome.ok) {
                button.disabled = false;
                snapshotsErrorBox.hidden = false;
                snapshotsErrorBox.textContent = await window.AppTranslations.trans('settings_backup.restore_error_text');
            }
        });
    });
})();
