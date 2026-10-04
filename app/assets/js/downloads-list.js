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

// Polls GET /downloads/status (App\Controller\DownloadsStatusController, issue #854) to keep the
// downloads/index.html.twig table's live fields current. A setTimeout CHAIN, not setInterval: the
// next request is only scheduled once the previous one has settled (success, failure or abort),
// so a slow or stuck qBittorrent never piles up more than one in-flight request — the endpoint's
// own 2s HTTP-client timeout (app.qbittorrent.status_client in services.yaml) bounds how long that
// one request can hold a FrankenPHP worker thread either way.
//
// Every row's text (status, size, speed, ETA, peers) is already translated/formatted server-side
// by App\Service\Download\DownloadsOverviewBuilder — this module only ever assigns fields it got
// back verbatim, never computes or translates anything itself.
(function () {
    const POLL_INTERVAL_MS = 2000;

    function buildCell(text) {
        const td = document.createElement('td');
        td.textContent = text ?? '';

        return td;
    }

    function buildRow(row, noCardLabel) {
        const tr = document.createElement('tr');
        tr.dataset.infoHash = row.infoHash;
        if (!row.hasCard) {
            tr.classList.add('table-warning');
        }

        const nameCell = document.createElement('td');
        const bdi = document.createElement('bdi');
        bdi.textContent = row.displayName;

        if (row.animeUrl) {
            const link = document.createElement('a');
            link.href = row.animeUrl;
            link.appendChild(bdi);
            nameCell.appendChild(link);
        } else {
            nameCell.appendChild(bdi);
        }

        if (!row.hasCard) {
            const badge = document.createElement('span');
            badge.className = 'badge text-bg-secondary ms-1';
            badge.textContent = noCardLabel;
            nameCell.appendChild(badge);
        }

        tr.appendChild(nameCell);
        tr.appendChild(buildCell(row.statusText));
        tr.appendChild(buildCell(row.sizeText));
        tr.appendChild(buildCell(row.progressText));
        tr.appendChild(buildCell(row.downloadSpeedText));
        tr.appendChild(buildCell(row.uploadSpeedText));
        tr.appendChild(buildCell(row.etaText));
        tr.appendChild(buildCell(row.peersText));
        tr.appendChild(buildCell(row.targetStorageName));

        // Action buttons/forms (issue #856) need a CSRF token this poll response never carries —
        // they are only ever rendered server-side (downloads/index.html.twig). A brand new row
        // (a download started elsewhere while this page is open) gets an empty cell here until
        // the next full page load; an already-rendered row keeps its real one, see render()
        // below, which transplants it onto this placeholder before the row is inserted.
        const actionsCell = document.createElement('td');
        actionsCell.dataset.field = 'actions';
        tr.appendChild(actionsCell);

        return tr;
    }

    function mountDownloadsList(root) {
        const statusUrl = root.dataset.statusUrl;
        const noCardLabel = root.dataset.noCardLabel;
        const banner = root.querySelector('[data-downloads-banner]');
        const tbody = root.querySelector('[data-downloads-rows]');
        const tableWrapper = root.querySelector('[data-downloads-table-wrapper]');
        const emptyMessage = root.querySelector('[data-downloads-empty]');

        // Mirrors the three states a single poll cycle moves through: nothing scheduled and
        // nothing in flight (both null), a timer waiting out the interval (timer set), or a
        // request in flight (controller set) — never more than one of the two at once.
        let timer = null;
        let controller = null;
        let stopped = false;

        function render(data) {
            if (banner) {
                banner.hidden = data.qbittorrentAvailable;
            }

            const hasAnyRow = data.rows.length > 0 || data.orphans.length > 0;
            if (tableWrapper) {
                tableWrapper.hidden = !hasAnyRow;
            }
            if (emptyMessage) {
                emptyMessage.hidden = hasAnyRow;
            }

            if (!tbody) {
                return;
            }

            // Preserve each existing row's server-rendered actions cell (issue #856) across the
            // wholesale tbody rebuild below — it carries CSRF tokens this JSON response never
            // includes, and swapping it onto the freshly built row (matched by infoHash) is what
            // keeps the action buttons from disappearing on every 2-second poll tick.
            const preservedActionsByHash = new Map();
            tbody.querySelectorAll('tr[data-info-hash]').forEach((tr) => {
                const actionsCell = tr.querySelector('[data-field="actions"]');
                if (actionsCell) {
                    preservedActionsByHash.set(tr.dataset.infoHash, actionsCell);
                }
            });

            tbody.replaceChildren();
            data.rows.concat(data.orphans).forEach((row) => {
                const tr = buildRow(row, noCardLabel);
                const preservedActions = preservedActionsByHash.get(row.infoHash);
                if (preservedActions) {
                    tr.querySelector('[data-field="actions"]').replaceWith(preservedActions);
                }
                tbody.appendChild(tr);
            });
        }

        function scheduleNext() {
            if (stopped || document.hidden) {
                return;
            }
            timer = setTimeout(poll, POLL_INTERVAL_MS);
        }

        async function poll() {
            timer = null;
            if (stopped || document.hidden) {
                return;
            }

            const abortController = new AbortController();
            controller = abortController;

            try {
                const response = await fetch(statusUrl, { signal: abortController.signal });
                if (!response.ok) {
                    throw new Error(`Downloads status request failed with status ${response.status}`);
                }
                const data = await response.json();
                if (!abortController.signal.aborted) {
                    render(data);
                }
            } catch {
                // A failed/aborted poll just leaves the table showing its last known state — the
                // next tick (or the next visibility change) tries again.
            } finally {
                controller = null;
                scheduleNext();
            }
        }

        function onVisibilityChange() {
            if (document.hidden) {
                if (timer !== null) {
                    clearTimeout(timer);
                    timer = null;
                }
                if (controller !== null) {
                    controller.abort();
                }

                return;
            }

            // Both null means no cycle is currently running (it was fully stopped while hidden,
            // see above) — resume immediately rather than waiting out a fresh interval.
            if (timer === null && controller === null) {
                poll();
            }
        }

        document.addEventListener('visibilitychange', onVisibilityChange);
        if (!document.hidden) {
            poll();
        }

        return function unmountDownloadsList() {
            stopped = true;
            document.removeEventListener('visibilitychange', onVisibilityChange);
            if (timer !== null) {
                clearTimeout(timer);
            }
            if (controller !== null) {
                controller.abort();
            }
        };
    }

    window.Controller.registerControl('downloads-list', mountDownloadsList);
})();
