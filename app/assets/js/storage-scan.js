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

// Wires storage/list.html.twig's #storage-scan section (issue #140, Таск 3 часть 7.5) to the
// scan just triggered from this page: subscribes through window.ScanWatcher (scan.js, issue
// #139) to the storage_id the server put in the redirect's query string, then renders progress
// and the final scan.done/scan.failed payload without a page reload.
//
// The same section can instead carry data-items-url (issue #998): the results of a scan journal
// run are then fetched from that endpoint and rendered by the very same code, with no
// subscription. Actions (confirm, create an entry) exist only when the endpoint says the run is
// the latest one, and only for items that are not resolved yet; resolved items are collapsed.
// Any key of an item may be missing — stored items are a versioned format.
(function () {
    const GROUPS = [
        { type: 'Updated', labelKey: 'storage_list.group_updated' },
        { type: 'FilesMissing', labelKey: 'storage_list.group_files_missing' },
        { type: 'AutoLinked', labelKey: 'storage_list.group_auto_linked' },
        { type: 'NeedsManualEntry', labelKey: 'storage_list.group_needs_manual_entry' },
        { type: 'NeedsConfirmation', labelKey: 'storage_list.group_needs_confirmation' },
        { type: 'Conflict', labelKey: 'storage_list.group_conflict' },
        { type: 'Error', labelKey: 'storage_list.group_error' },
    ];

    // Carries the structured 409 conflict body (issue #832) through fetch()'s rejection path,
    // distinguishing "the candidate is already in the catalog, linked elsewhere" from a plain
    // network/server failure, which confirmCandidate()'s catch() below needs to render differently.
    class ConflictError extends Error {
        constructor(conflict) {
            super('storage scan confirm conflict');
            this.conflict = conflict;
        }
    }

    // The server refused a confirmation because the folder is no longer in the storage (HTTP 410).
    class EntryMissingError extends Error {
        constructor() {
            super('storage scan confirm entry missing');
        }
    }

    // Guards against a scan that finished (or is still running) before this page managed to
    // subscribe: without it a missed scan.progress/scan.done leaves the progress bar stuck at
    // 0% forever with no feedback (issue #156).
    const NO_RESPONSE_TIMEOUT_MS = 15000;

    // One mount = one scan subscription (issue #734): every piece of state below (the no-response
    // timer, the ScanWatcher subscription itself) lives in this function's closure, created fresh
    // per mount and torn down by the returned unmount() — nothing is shared across instances at
    // module scope.
    function mountStorageScan(root) {
        const storageId = root.dataset.storageId;
        const confirmUrl = root.dataset.confirmUrl;
        const confirmToken = root.dataset.confirmToken;
        const animeNewUrl = root.dataset.animeNewUrl;
        const itemsUrl = root.dataset.itemsUrl ?? null;

        // False for a journal run that is not the latest one; a live scan is always actionable.
        let actionsEnabled = true;

        // The server now decides whether a scan is running from its own job lock, not from this
        // URL's `?started=1` (issue #834 review) — stripping it here keeps a later F5 or
        // back/forward navigation from looking like a fresh "just triggered a scan" request.
        if (itemsUrl === null && window.history && window.location.search !== '') {
            window.history.replaceState(null, '', window.location.pathname);
        }

        const progressBox = root.querySelector('#storage-scan-progress');
        const progressBar = root.querySelector('#storage-scan-progress-bar');
        const progressText = root.querySelector('#storage-scan-progress-text');
        const errorBox = root.querySelector('#storage-scan-error');
        const resultsBox = root.querySelector('#storage-scan-results');

        let noResponseTimer = itemsUrl === null ? setTimeout(onNoResponse, NO_RESPONSE_TIMEOUT_MS) : null;

        function clearNoResponseTimer() {
            if (noResponseTimer !== null) {
                clearTimeout(noResponseTimer);
                noResponseTimer = null;
            }
        }

        async function onNoResponse() {
            noResponseTimer = null;
            progressBox.hidden = true;
            errorBox.hidden = false;
            errorBox.textContent = await window.AppTranslations.trans('storage_list.scan_no_response');
        }

        async function onProgress(data) {
            clearNoResponseTimer();
            const percent = data.percent ?? 0;
            // Width is set via the style property (CSSOM), not a rendered `style` attribute in Twig
            // markup — the app's CSP (style-src 'self') blocks inline styles (issue #629).
            progressBar.style.width = `${percent}%`;
            progressBar.setAttribute('aria-valuenow', String(percent));
            progressText.textContent = await window.AppTranslations.trans(
                'storage_list.scan_progress_text',
                { processed: data.processed, total: data.total, percent },
            );
        }

        async function onFailed(data) {
            clearNoResponseTimer();
            progressBox.hidden = true;
            errorBox.hidden = false;
            errorBox.textContent = data.reason === 'marker_conflict'
                ? await window.AppTranslations.trans('storage_list.scan_failed_marker_conflict')
                : await window.AppTranslations.trans('storage_list.scan_failed_exception', { message: data.message });
        }

        async function buildInfoItem(item, labelKey) {
            const li = document.createElement('li');
            li.className = 'list-group-item';
            li.textContent = await window.AppTranslations.trans(labelKey, {
                title: item.anime?.title ?? item.storage_path ?? '',
                path: item.storage_path ?? '',
            });

            return li;
        }

        async function buildAutoLinkedItem(item) {
            const li = document.createElement('li');
            li.className = 'list-group-item';
            li.textContent = await window.AppTranslations.trans('storage_list.auto_linked_text', {
                title: item.anime?.title ?? item.storage_path ?? '',
            });

            return li;
        }

        // Link to the new-entry form prefilled with the cleaned name, this storage and the folder;
        // shared by the zero-candidate item and the "none of these" escape of the confirmation card.
        async function buildManualEntryLink(item, labelKey, labelParams) {
            const link = document.createElement('a');
            const params = new URLSearchParams({
                title: item.cleaned_name ?? '',
                storage_id: storageId,
                storage_path: item.storage_path ?? '',
            });
            link.href = `${animeNewUrl}?${params.toString()}`;
            link.textContent = await window.AppTranslations.trans(labelKey, labelParams);

            return link;
        }

        async function buildManualEntryItem(item, interactive) {
            const li = document.createElement('li');
            li.className = 'list-group-item';

            if (!interactive) {
                li.textContent = await window.AppTranslations.trans('storage_list.manual_entry_text', {
                    path: item.storage_path ?? '',
                });

                return li;
            }

            li.appendChild(await buildManualEntryLink(item, 'storage_list.create_entry_link', {
                title: item.cleaned_name ?? item.storage_path ?? '',
            }));

            return li;
        }

        function confirmCandidate(item, candidate, li, radios, button) {
            button.disabled = true;

            // A plugin candidate carries its real pluginId/externalId (issue #832) so the server
            // can find-or-create by that pair instead of a bare title it could never dedupe the
            // catalog by.
            const body = candidate.anime_id != null
                ? { token: confirmToken, storage_path: item.storage_path, anime_id: candidate.anime_id }
                : {
                    token: confirmToken,
                    storage_path: item.storage_path,
                    plugin_id: candidate.plugin_id,
                    external_id: candidate.external_id ?? '',
                    name: candidate.title,
                };

            fetch(confirmUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            })
                .then(async (response) => {
                    if (response.status === 410) {
                        throw new EntryMissingError();
                    }
                    if (response.status === 409) {
                        const data = await response.json();
                        throw new ConflictError(data.conflict);
                    }
                    if (!response.ok) {
                        throw new Error(`Confirm request failed with status ${response.status}`);
                    }

                    return response.json();
                })
                .then(async (data) => {
                    li.replaceChildren();
                    const title = data.anime?.title ?? candidate.title;
                    li.textContent = await window.AppTranslations.trans(
                        data.filled_from_plugin === false
                            ? 'storage_list.confirmed_without_plugin_data_text'
                            : 'storage_list.confirmed_text',
                        { title },
                    );
                })
                .catch(async (reason) => {
                    if (reason instanceof EntryMissingError) {
                        li.replaceChildren();
                        li.textContent = await window.AppTranslations.trans('storage_list.entry_missing');

                        return;
                    }

                    if (reason instanceof ConflictError && reason.conflict) {
                        li.replaceChildren();
                        li.textContent = await window.AppTranslations.trans('storage_list.conflict_text', {
                            title: reason.conflict.anime?.title ?? candidate.title,
                            path: reason.conflict.storage_path ?? '',
                        });

                        return;
                    }

                    button.disabled = false;
                    radios.forEach((radio) => { radio.disabled = false; });

                    const error = document.createElement('p');
                    error.className = 'alert alert-danger mt-2';
                    error.textContent = await window.AppTranslations.trans('storage_list.confirm_error');
                    li.appendChild(error);
                });
        }

        async function buildConfirmationItem(item, index, interactive) {
            const li = document.createElement('li');
            li.className = 'list-group-item';

            const path = document.createElement('p');
            path.className = 'mb-2';
            path.textContent = item.storage_path ?? '';
            li.appendChild(path);

            if (item.cleaned_name && item.cleaned_name !== item.storage_path) {
                const cleaned = document.createElement('p');
                cleaned.className = 'mb-2 text-body-secondary';
                cleaned.textContent = await window.AppTranslations.trans('storage_list.cleaned_name_text', {
                    name: item.cleaned_name,
                });
                li.appendChild(cleaned);
            }

            const candidates = Array.isArray(item.candidates) ? item.candidates : [];

            if (!interactive) {
                const titles = candidates.map((candidate) => candidate.title ?? '').filter((title) => title !== '');
                if (titles.length > 0) {
                    const summary = document.createElement('p');
                    summary.className = 'mb-0 text-body-secondary';
                    summary.textContent = await window.AppTranslations.trans('storage_list.candidates_text', {
                        titles: titles.join(', '),
                    });
                    li.appendChild(summary);
                }

                return li;
            }

            const radios = [];
            const radioGroupName = `storage-scan-confirm-${index}`;

            for (const [candidateIndex, candidate] of candidates.entries()) {
                const wrapper = document.createElement('div');
                wrapper.className = 'form-check';

                const radio = document.createElement('input');
                radio.type = 'radio';
                radio.className = 'form-check-input';
                radio.id = `${radioGroupName}-${candidateIndex}`;
                radio.name = radioGroupName;
                radio.value = String(candidateIndex);
                if (candidateIndex === 0) {
                    radio.checked = true;
                }
                radios.push(radio);

                const label = document.createElement('label');
                label.className = 'form-check-label';
                label.htmlFor = radio.id;
                const source = candidate.anime_id != null
                    ? await window.AppTranslations.trans('storage_list.candidate_source_catalog')
                    : (candidate.plugin_name ?? candidate.plugin_id ?? '');
                label.textContent = await window.AppTranslations.trans('storage_list.candidate_label', {
                    title: candidate.title ?? '',
                    source,
                });

                wrapper.appendChild(radio);
                wrapper.appendChild(label);
                li.appendChild(wrapper);
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-primary mt-2';
            button.textContent = await window.AppTranslations.trans('storage_list.confirm_button');
            button.addEventListener('click', () => {
                const checked = radios.find((radio) => radio.checked);
                if (!checked) {
                    return;
                }

                radios.forEach((radio) => { radio.disabled = true; });
                confirmCandidate(item, candidates[Number(checked.value)], li, radios, button);
            });
            li.appendChild(button);

            const none = document.createElement('p');
            none.className = 'mt-2 mb-0';
            none.appendChild(await buildManualEntryLink(item, 'storage_list.none_match_link'));
            li.appendChild(none);

            return li;
        }

        async function buildConflictItem(item) {
            const li = document.createElement('li');
            li.className = 'list-group-item';
            li.textContent = await window.AppTranslations.trans('storage_list.conflict_text', {
                title: item.anime?.title ?? item.storage_path ?? '',
                path: item.already_linked_storage_path ?? '',
            });

            return li;
        }

        async function buildErrorItem(item) {
            const li = document.createElement('li');
            li.className = 'list-group-item';
            li.textContent = await window.AppTranslations.trans('storage_list.error_text', {
                path: item.storage_path ?? '',
                message: item.error_message ?? '',
            });

            return li;
        }

        // Every ScanItemType case is handled here (issue #832) — an item of a type this
        // function does not recognize used to be silently dropped from the results list, which
        // is exactly how a new server-side type (Conflict, Error) would have gone unnoticed.
        async function buildItem(type, item, index, interactive) {
            switch (type) {
                case 'Updated':
                    return buildInfoItem(item, 'storage_list.updated_text');
                case 'FilesMissing':
                    return buildInfoItem(item, 'storage_list.files_missing_text');
                case 'AutoLinked':
                    return buildAutoLinkedItem(item);
                case 'NeedsManualEntry':
                    return buildManualEntryItem(item, interactive);
                case 'NeedsConfirmation':
                    return buildConfirmationItem(item, index, interactive);
                case 'Conflict':
                    return buildConflictItem(item);
                case 'Error':
                    return buildErrorItem(item);
                default:
                    return null;
            }
        }

        async function buildGroup(group, items) {
            const section = document.createElement('section');
            section.className = 'storage-scan__group mb-4';

            const heading = document.createElement('h3');
            heading.className = 'h6';
            heading.textContent = await window.AppTranslations.trans(group.labelKey);
            section.appendChild(heading);

            // Only a journal item can be resolved (the server computes it); the rest of the items
            // keep their actions as long as the run is actionable.
            const open = items.filter((item) => item.resolved !== true);
            const resolved = items.filter((item) => item.resolved === true);

            const list = document.createElement('ul');
            list.className = 'list-group';
            for (const [index, item] of open.entries()) {
                const li = await buildItem(group.type, item, index, actionsEnabled);
                if (li !== null) {
                    list.appendChild(li);
                }
            }
            section.appendChild(list);

            if (resolved.length > 0) {
                const details = document.createElement('details');
                details.className = 'mt-2';

                const summary = document.createElement('summary');
                summary.textContent = await window.AppTranslations.trans('storage_list.resolved_summary', {
                    count: resolved.length,
                });
                details.appendChild(summary);

                const resolvedList = document.createElement('ul');
                resolvedList.className = 'list-group mt-2';
                for (const [index, item] of resolved.entries()) {
                    const li = await buildItem(group.type, item, index, false);
                    if (li !== null) {
                        resolvedList.appendChild(li);
                    }
                }
                details.appendChild(resolvedList);
                section.appendChild(details);
            }

            return section;
        }

        async function renderResults(items) {
            resultsBox.hidden = false;
            resultsBox.replaceChildren();

            for (const group of GROUPS) {
                const groupItems = items.filter((item) => item.type === group.type);
                if (groupItems.length > 0) {
                    resultsBox.appendChild(await buildGroup(group, groupItems));
                }
            }

            if (resultsBox.children.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'text-muted';
                empty.textContent = await window.AppTranslations.trans('storage_list.scan_result_empty');
                resultsBox.appendChild(empty);
            }
        }

        async function onDone(data) {
            clearNoResponseTimer();
            progressBox.hidden = true;

            await renderResults(Array.isArray(data.items) ? data.items : []);
        }

        async function loadJournalRun() {
            try {
                const response = await fetch(itemsUrl, { headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    throw new Error(`Items request failed with status ${response.status}`);
                }

                const data = await response.json();
                actionsEnabled = data.latest === true;
                await renderResults(Array.isArray(data.items) ? data.items : []);
            } catch {
                errorBox.hidden = false;
                errorBox.textContent = await window.AppTranslations.trans('storage_list.journal_load_error');
            }
        }

        // window.AppTranslations.getCatalogue() (see translations.js) is a network round-trip and
        // scan.progress/scan.done may already be on the bus by the time it resolves — subscribing
        // does not need to wait on it (issue #156).
        if (itemsUrl !== null) {
            loadJournalRun();

            return function unmountJournalRun() {};
        }

        window.ScanWatcher.watch(storageId, { onProgress, onDone, onFailed });

        // Symmetric demount (issue #734): a node removed by an htmx swap must not leave its timer
        // running or its ScanWatcher subscription live — the worst case in this app's own markup is
        // settings/market/_refresh_area.html.twig's hx-trigger="load delay:2s" pattern, which would
        // otherwise pile up one dangling timer/subscription per reload, forever, on any section that
        // reused it with a #storage-scan-shaped control.
        return function unmountStorageScan() {
            clearNoResponseTimer();
            window.ScanWatcher.unwatch(storageId);
        };
    }

    window.Controller.registerControl('storage-scan', mountStorageScan);
})();
