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

// Wires the onboarding invitation's "Import from AnimeDB v1" card (issue #953) to
// window.animeDb.pickFolder() (native/dialog/index.js) and importV1Start()/importV1Cancel()
// (native/import-v1/index.js). Progress and the outcome arrive over the /ws bus as
// import_v1.progress / .done / .failed (V1ImportService, ImportV1Command). The report is shown
// from the done event only and is not stored anywhere: it does not outlive the page.
(function () {
    // The order of the import's phases; the bar is split evenly between them.
    const PHASES = ['read', 'records', 'covers'];

    /**
     * The lines of the report as [translationKey, params] pairs, mirroring V1ImportResult::render():
     * the same import_v1.report_* messages, so the console and this screen say the same thing.
     * Genres left out by design and genres with no counterpart stay separate lines on purpose.
     */
    function reportLines(data) {
        const names = (data.namesJapanese || 0) + (data.namesRussian || 0) + (data.namesUnknownLocale || 0);
        const lines = [
            ['import_v1.report_created', { count: data.animeCreated, fromLabel: data.withStatusFromLabel, byDefault: data.withDefaultStatus }],
            ['import_v1.report_names', { count: names, ja: data.namesJapanese, ru: data.namesRussian, none: data.namesUnknownLocale }],
            ['import_v1.report_related', { sources: data.sources, descriptions: data.descriptions, studios: data.studios, labels: data.labels }],
            ['import_v1.report_genres', { count: data.genresMapped }],
            ['import_v1.report_genres_dropped', { count: data.genresDroppedByDesign }],
            ['import_v1.report_genres_unmapped', { count: data.genresUnmapped, names: (data.unmappedGenreNames || []).join(', ') }],
            ['import_v1.report_covers', { imported: data.coversImported, missing: data.coversMissing }],
            ['import_v1.report_storages', { count: data.storagesCreated, unavailable: data.storagesUnavailable }],
        ];

        if ((data.skippedStorageNames || []).length > 0) {
            lines.push(['import_v1.report_storages_skipped', { names: data.skippedStorageNames.join(', ') }]);
        }
        if ((data.episodesDroppedTitles || []).length > 0) {
            lines.push(['import_v1.report_episodes_dropped', { count: data.episodesDroppedTitles.length, titles: data.episodesDroppedTitles.join(', ') }]);
        }
        if (data.endDatesSynthesized > 0) {
            lines.push(['import_v1.report_end_dates', { count: data.endDatesSynthesized }]);
        }
        if (data.statusesDowngraded > 0) {
            lines.push(['import_v1.report_status_downgraded', { count: data.statusesDowngraded }]);
        }
        if (data.needsAttention > 0) {
            lines.push(['import_v1.report_needs_attention', { count: data.needsAttention }]);
        }

        return lines;
    }

    // InvalidV1InstallationException::REASON_* — the only refusals that have an import_v1.error_* message.
    const KNOWN_REASONS = ['not_v1_installation', 'catalog_not_empty', 'invalid_record'];

    // How long to wait for import_v1.done after the process exited successfully before falling
    // back to a report-less message (the bus is polled, so the event may trail the exit slightly).
    const DONE_GRACE_MS = 3000;

    function mountOnboardingImportV1(root) {
        const pickButton = root.querySelector('#onboarding-import-v1-pick');
        const cancelButton = root.querySelector('#onboarding-import-v1-cancel');
        const progressBox = root.querySelector('#onboarding-import-v1-progress');
        const progressBar = root.querySelector('#onboarding-import-v1-progress-bar');
        const progressText = root.querySelector('#onboarding-import-v1-progress-text');
        const errorBox = root.querySelector('#onboarding-import-v1-error');
        const cancelledBox = root.querySelector('#onboarding-import-v1-cancelled');
        const resultBox = root.querySelector('#onboarding-import-v1-result');
        const reportList = root.querySelector('#onboarding-import-v1-report');
        const openButton = root.querySelector('#onboarding-import-v1-open');
        if (!pickButton) {
            return;
        }

        // The import only runs through Electron's native layer — a plain browser tab has nowhere
        // to spawn app:catalog:import-v1.
        if (!window.animeDb || !window.animeDb.importV1Start || !window.animeDb.pickFolder) {
            root.hidden = true;
            return;
        }

        let socket = null;
        let cancelRequested = false;
        let running = false;
        let settled = false;

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

            if (!running) {
                return;
            }
            switch (payload.event) {
                case 'import_v1.progress':
                    onProgress(payload.data);
                    break;
                case 'import_v1.done':
                    onDone(payload.data);
                    break;
                case 'import_v1.failed':
                    onFailed(payload.data);
                    break;
                default:
                    break;
            }
        }

        function setPercent(percent) {
            progressBar.style.width = `${percent}%`;
            progressBar.parentElement.setAttribute('aria-valuenow', String(percent));
        }

        function resetControls() {
            running = false;
            progressBox.hidden = true;
            cancelButton.hidden = true;
            pickButton.hidden = false;
            pickButton.disabled = false;
            setPercent(0);
        }

        async function onProgress(data) {
            const index = PHASES.indexOf(data.phase);
            if (index === -1) {
                return;
            }

            const total = data.total > 0 ? data.total : 1;
            const ratio = Math.min(data.current / total, 1);
            progressBox.hidden = false;
            setPercent(Math.round(((index + ratio) / PHASES.length) * 100));
            progressText.textContent = await window.AppTranslations.trans(`onboarding.import_v1_progress_${data.phase}`, {
                current: data.current,
                total: data.total,
            });
        }

        async function onDone(data) {
            settled = true;
            resetControls();
            reportList.replaceChildren();
            for (const [key, params] of reportLines(data)) {
                const item = document.createElement('li');
                item.textContent = await window.AppTranslations.trans(key, params);
                reportList.appendChild(item);
            }
            resultBox.hidden = false;
        }

        async function onFailed(data) {
            settled = true;
            resetControls();
            showError(data.reason, data.params);
        }

        async function showError(reason, params) {
            errorBox.hidden = false;
            errorBox.textContent = KNOWN_REASONS.includes(reason)
                ? await window.AppTranslations.trans(`import_v1.error_${reason}`, params || {})
                : await window.AppTranslations.trans('onboarding.import_v1_error_generic');
        }

        function waitForDone() {
            return new Promise((resolve) => {
                const startedAt = Date.now();
                const timer = setInterval(() => {
                    if (settled || Date.now() - startedAt >= DONE_GRACE_MS) {
                        clearInterval(timer);
                        resolve();
                    }
                }, 50);
            });
        }

        pickButton.addEventListener('click', async () => {
            const folder = await window.animeDb.pickFolder();
            if (!folder) {
                return;
            }

            errorBox.hidden = true;
            cancelledBox.hidden = true;
            resultBox.hidden = true;
            progressBox.hidden = false;
            setPercent(0);
            progressText.textContent = '';
            pickButton.hidden = true;
            cancelButton.hidden = false;
            cancelRequested = false;
            settled = false;
            running = true;

            if (socket === null) {
                connect();
            }

            // import_v1.done / .failed carry the outcome in the common case. The return value only
            // matters for what never reaches the bus: a cancel (killing the process by PID bypasses
            // the command's own publishing), a process that could not spawn, a crash.
            const outcome = await window.animeDb.importV1Start(folder);
            // A result from the bus wins over a late cancel: the catalog is already filled (or the
            // refusal already shown), so "cancelled" would be untrue.
            if (settled) {
                cancelRequested = false;
            } else if (cancelRequested) {
                cancelRequested = false;
                resetControls();
                cancelledBox.hidden = false;
            } else if (outcome.ok) {
                await waitForDone();
                if (!settled) {
                    resetControls();
                    reportList.replaceChildren();
                    const item = document.createElement('li');
                    item.textContent = await window.AppTranslations.trans('onboarding.import_v1_done_no_report');
                    reportList.appendChild(item);
                    resultBox.hidden = false;
                }
            } else {
                resetControls();
                showError(null);
            }
        });

        cancelButton.addEventListener('click', () => {
            cancelRequested = true;
            cancelButton.disabled = true;
            window.animeDb.importV1Cancel().finally(() => {
                cancelButton.disabled = false;
            });
        });

        // The invitation disappears on the next page request, once the catalog is no longer empty.
        openButton.addEventListener('click', () => {
            window.location.reload();
        });

        return function unmountOnboardingImportV1() {
            if (socket) {
                socket.close();
                socket = null;
            }
        };
    }

    window.Controller.registerControl('onboarding-import-v1', mountOnboardingImportV1);
})();
