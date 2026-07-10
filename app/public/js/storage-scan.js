/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
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

    let messages = {};

    function trans(key, fallback) {
        return Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : fallback;
    }

    function format(template, params) {
        return Object.keys(params).reduce(
            (text, name) => text.replace(`%${name}%`, String(params[name])),
            template,
        );
    }

    function onProgress(data) {
        const percent = data.percent ?? 0;
        progressBar.value = percent;
        progressText.textContent = format(
            trans('storage_list.scan_progress_text', '%processed%/%total% (%percent%%)'),
            { processed: data.processed, total: data.total, percent },
        );
    }

    function onFailed(data) {
        progressBox.hidden = true;
        errorBox.hidden = false;
        errorBox.textContent = data.reason === 'marker_conflict'
            ? trans('storage_list.scan_failed_marker_conflict', data.message)
            : format(trans('storage_list.scan_failed_exception', 'Scan failed: %message%'), { message: data.message });
    }

    function buildInfoItem(item, labelKey) {
        const li = document.createElement('li');
        li.textContent = format(trans(labelKey, '%title% (%path%)'), {
            title: item.anime?.title ?? item.storage_path,
            path: item.storage_path,
        });

        return li;
    }

    function buildAutoLinkedItem(item) {
        const li = document.createElement('li');
        li.textContent = format(trans('storage_list.auto_linked_text', 'Added automatically: %title%'), {
            title: item.anime?.title ?? item.storage_path,
        });

        return li;
    }

    function buildManualEntryItem(item) {
        const li = document.createElement('li');

        const link = document.createElement('a');
        const params = new URLSearchParams({
            title: item.cleaned_name ?? '',
            storage_id: storageId,
            storage_path: item.storage_path,
        });
        link.href = `${animeNewUrl}?${params.toString()}`;
        link.textContent = format(trans('storage_list.create_entry_link', 'Create entry: %title%'), {
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
            .then((data) => {
                li.replaceChildren();
                li.textContent = format(trans('storage_list.confirmed_text', 'Confirmed: %title%'), {
                    title: data.anime?.title ?? candidate.title,
                });
            })
            .catch(() => {
                button.disabled = false;
                radios.forEach((radio) => { radio.disabled = false; });

                const error = document.createElement('p');
                error.className = 'storage-scan__item-error';
                error.textContent = trans('storage_list.confirm_error', 'Failed to confirm the selection.');
                li.appendChild(error);
            });
    }

    function buildConfirmationItem(item, index) {
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
        button.textContent = trans('storage_list.confirm_button', 'Confirm');
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

    function buildItem(type, item, index) {
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

    function buildGroup(group, items) {
        const section = document.createElement('section');
        section.className = 'storage-scan__group';

        const heading = document.createElement('h3');
        heading.textContent = trans(group.labelKey, group.type);
        section.appendChild(heading);

        const list = document.createElement('ul');
        items.forEach((item, index) => {
            const li = buildItem(group.type, item, index);
            if (li !== null) {
                list.appendChild(li);
            }
        });
        section.appendChild(list);

        return section;
    }

    function onDone(data) {
        progressBox.hidden = true;
        resultsBox.hidden = false;
        resultsBox.replaceChildren();

        const items = Array.isArray(data.items) ? data.items : [];

        GROUPS.forEach((group) => {
            const groupItems = items.filter((item) => item.type === group.type);
            if (groupItems.length > 0) {
                resultsBox.appendChild(buildGroup(group, groupItems));
            }
        });

        if (resultsBox.children.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = trans('storage_list.scan_result_empty', 'Nothing found.');
            resultsBox.appendChild(empty);
        }
    }

    async function init() {
        try {
            messages = await window.AppTranslations.getCatalogue();
        } catch {
            messages = {};
        }

        window.ScanWatcher.watch(storageId, { onProgress, onDone, onFailed });
    }

    init();
})();
