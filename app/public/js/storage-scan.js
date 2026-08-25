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
(function () {
    const GROUPS = [
        { type: 'Updated', labelKey: 'storage_list.group_updated' },
        { type: 'FilesMissing', labelKey: 'storage_list.group_files_missing' },
        { type: 'AutoLinked', labelKey: 'storage_list.group_auto_linked' },
        { type: 'NeedsManualEntry', labelKey: 'storage_list.group_needs_manual_entry' },
        { type: 'NeedsConfirmation', labelKey: 'storage_list.group_needs_confirmation' },
    ];

    const root = document.getElementById('storage-scan');
    if (!root) {
        return;
    }

    const storageId = root.dataset.storageId;
    const confirmUrl = root.dataset.confirmUrl;
    const confirmToken = root.dataset.confirmToken;
    const animeNewUrl = root.dataset.animeNewUrl;

    const progressBox = document.getElementById('storage-scan-progress');
    const progressBar = document.getElementById('storage-scan-progress-bar');
    const progressText = document.getElementById('storage-scan-progress-text');
    const errorBox = document.getElementById('storage-scan-error');
    const resultsBox = document.getElementById('storage-scan-results');

    // Guards against a scan that finished (or is still running) before this page managed to
    // subscribe: without it a missed scan.progress/scan.done leaves the progress bar stuck at
    // 0% forever with no feedback (issue #156).
    const NO_RESPONSE_TIMEOUT_MS = 15000;

    // FIRST STRONG ISOLATE / POP DIRECTIONAL ISOLATE: interpolated values (anime titles, storage
    // paths) come from sources written in Latin or Japanese script. Rendered as plain text
    // (.textContent, not HTML — a <bdi> element is not available here), an unisolated value
    // inside an RTL message can reorder adjacent characters, most visibly brackets and colons
    // (issue #450).
    const BIDI_ISOLATE_START = '⁨';
    const BIDI_ISOLATE_END   = '⁩';

    let noResponseTimer = setTimeout(onNoResponse, NO_RESPONSE_TIMEOUT_MS);

    function format(template, params) {
        return Object.keys(params).reduce(
            (text, name) => text.replace(`%${name}%`, `${BIDI_ISOLATE_START}${String(params[name])}${BIDI_ISOLATE_END}`),
            template,
        );
    }

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
        progressBar.value = percent;
        progressText.textContent = format(
            await window.AppTranslations.trans('storage_list.scan_progress_text'),
            { processed: data.processed, total: data.total, percent },
        );
    }

    async function onFailed(data) {
        clearNoResponseTimer();
        progressBox.hidden = true;
        errorBox.hidden = false;
        errorBox.textContent = data.reason === 'marker_conflict'
            ? await window.AppTranslations.trans('storage_list.scan_failed_marker_conflict')
            : format(await window.AppTranslations.trans('storage_list.scan_failed_exception'), { message: data.message });
    }

    async function buildInfoItem(item, labelKey) {
        const li = document.createElement('li');
        li.textContent = format(await window.AppTranslations.trans(labelKey), {
            title: item.anime?.title ?? item.storage_path,
            path: item.storage_path,
        });

        return li;
    }

    async function buildAutoLinkedItem(item) {
        const li = document.createElement('li');
        li.textContent = format(await window.AppTranslations.trans('storage_list.auto_linked_text'), {
            title: item.anime?.title ?? item.storage_path,
        });

        return li;
    }

    async function buildManualEntryItem(item) {
        const li = document.createElement('li');

        const link = document.createElement('a');
        const params = new URLSearchParams({
            title: item.cleaned_name ?? '',
            storage_id: storageId,
            storage_path: item.storage_path,
        });
        link.href = `${animeNewUrl}?${params.toString()}`;
        link.textContent = format(await window.AppTranslations.trans('storage_list.create_entry_link'), {
            title: item.cleaned_name ?? item.storage_path,
        });

        li.appendChild(link);

        return li;
    }

    function confirmCandidate(item, candidate, li, radios, button) {
        button.disabled = true;

        const body = candidate.anime_id !== null
            ? { token: confirmToken, storage_path: item.storage_path, anime_id: candidate.anime_id }
            : { token: confirmToken, storage_path: item.storage_path, name: candidate.title };

        fetch(confirmUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Confirm request failed with status ${response.status}`);
                }

                return response.json();
            })
            .then(async (data) => {
                li.replaceChildren();
                li.textContent = format(await window.AppTranslations.trans('storage_list.confirmed_text'), {
                    title: data.anime?.title ?? candidate.title,
                });
            })
            .catch(async () => {
                button.disabled = false;
                radios.forEach((radio) => { radio.disabled = false; });

                const error = document.createElement('p');
                error.className = 'storage-scan__item-error';
                error.textContent = await window.AppTranslations.trans('storage_list.confirm_error');
                li.appendChild(error);
            });
    }

    async function buildConfirmationItem(item, index) {
        const li = document.createElement('li');

        const path = document.createElement('p');
        path.textContent = item.cleaned_name ?? item.storage_path;
        li.appendChild(path);

        const radios = [];
        const radioGroupName = `storage-scan-confirm-${index}`;

        item.candidates.forEach((candidate, candidateIndex) => {
            const label = document.createElement('label');
            const radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = radioGroupName;
            radio.value = String(candidateIndex);
            if (candidateIndex === 0) {
                radio.checked = true;
            }
            radios.push(radio);

            label.appendChild(radio);
            label.append(` ${candidate.title}`);
            li.appendChild(label);
        });

        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = await window.AppTranslations.trans('storage_list.confirm_button');
        button.addEventListener('click', () => {
            const checked = radios.find((radio) => radio.checked);
            if (!checked) {
                return;
            }

            radios.forEach((radio) => { radio.disabled = true; });
            confirmCandidate(item, item.candidates[Number(checked.value)], li, radios, button);
        });
        li.appendChild(button);

        return li;
    }

    async function buildItem(type, item, index) {
        switch (type) {
            case 'Updated':
                return buildInfoItem(item, 'storage_list.updated_text');
            case 'FilesMissing':
                return buildInfoItem(item, 'storage_list.files_missing_text');
            case 'AutoLinked':
                return buildAutoLinkedItem(item);
            case 'NeedsManualEntry':
                return buildManualEntryItem(item);
            case 'NeedsConfirmation':
                return buildConfirmationItem(item, index);
            default:
                return null;
        }
    }

    async function buildGroup(group, items) {
        const section = document.createElement('section');
        section.className = 'storage-scan__group';

        const heading = document.createElement('h3');
        heading.textContent = await window.AppTranslations.trans(group.labelKey);
        section.appendChild(heading);

        const list = document.createElement('ul');
        for (const [index, item] of items.entries()) {
            const li = await buildItem(group.type, item, index);
            if (li !== null) {
                list.appendChild(li);
            }
        }
        section.appendChild(list);

        return section;
    }

    async function onDone(data) {
        clearNoResponseTimer();
        progressBox.hidden = true;
        resultsBox.hidden = false;
        resultsBox.replaceChildren();

        const items = Array.isArray(data.items) ? data.items : [];

        for (const group of GROUPS) {
            const groupItems = items.filter((item) => item.type === group.type);
            if (groupItems.length > 0) {
                resultsBox.appendChild(await buildGroup(group, groupItems));
            }
        }

        if (resultsBox.children.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = await window.AppTranslations.trans('storage_list.scan_result_empty');
            resultsBox.appendChild(empty);
        }
    }

    // window.AppTranslations.getCatalogue() (see translations.js) is a network round-trip and
    // scan.progress/scan.done may already be on the bus by the time it resolves — subscribing
    // does not need to wait on it (issue #156).
    window.ScanWatcher.watch(storageId, { onProgress, onDone, onFailed });
})();
