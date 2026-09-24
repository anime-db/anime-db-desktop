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

// Browser client for the WebSocket bus at /ws (App\Controller\WsController). Not to be confused
// with native/ws-client.js, which is a separate Electron main-process consumer of the same bus
// (issue #139). One shared connection is kept open for the whole page regardless of how many
// callers watch() a storage — parts 7.1/7.5 attach through this module instead of opening their
// own sockets.
(function () {
    const INITIAL_RECONNECT_DELAY = 1000;
    const MAX_RECONNECT_DELAY = 30000;

    const watchers = new Map();
    let socket = null;
    let reconnectTimer = null;
    let reconnectDelay = INITIAL_RECONNECT_DELAY;

    function dispatch(message) {
        let payload;
        try {
            payload = JSON.parse(message.data);
        } catch {
            return;
        }

        const data = payload && payload.data;
        if (!data || typeof data.storage_id === 'undefined') {
            return;
        }

        const callbacks = watchers.get(String(data.storage_id));
        if (!callbacks) {
            return;
        }

        switch (payload.event) {
            case 'scan.progress':
                callbacks.onProgress?.(data);
                break;
            case 'scan.done':
                callbacks.onDone?.(data);
                break;
            case 'scan.failed':
                callbacks.onFailed?.(data);
                break;
            default:
                break;
        }
    }

    function scheduleReconnect() {
        if (reconnectTimer !== null) {
            return;
        }

        reconnectTimer = setTimeout(() => {
            reconnectTimer = null;
            connect();
        }, reconnectDelay);
        reconnectDelay = Math.min(reconnectDelay * 2, MAX_RECONNECT_DELAY);
    }

    function connect() {
        socket = new WebSocket(`ws://${window.location.host}/ws`);

        socket.onopen = () => {
            reconnectDelay = INITIAL_RECONNECT_DELAY;
        };

        socket.onmessage = dispatch;

        socket.onclose = () => {
            socket = null;
            scheduleReconnect();
        };

        // onerror always fires before onclose; onclose handles reconnect
        socket.onerror = () => {};
    }

    /**
     * Registers callbacks for the WS events of a single storage scan. Replaces any previous
     * callbacks registered for the same storageId. Opens the shared connection on first use.
     */
    function watch(storageId, { onProgress, onDone, onFailed } = {}) {
        watchers.set(String(storageId), { onProgress, onDone, onFailed });

        if (socket === null && reconnectTimer === null) {
            connect();
        }
    }

    /**
     * Drops the callbacks registered for a storage scan (issue #734's demount hook for
     * storage-scan.js) — without this, a subscriber torn down by an htmx swap would keep getting
     * scan.progress/scan.done/scan.failed dispatched into detached DOM until (if ever) a fresh
     * subscription for the same storageId replaces it via watch() above.
     */
    function unwatch(storageId) {
        watchers.delete(String(storageId));
    }

    window.ScanWatcher = { watch, unwatch };
})();
