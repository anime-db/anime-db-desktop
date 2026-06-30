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

const { EventEmitter } = require('events');

const RECONNECT_DELAY = 1000;

class WsClient extends EventEmitter {
    constructor() {
        super();
        this._port   = null;
        this._socket = null;
    }

    /**
     * Opens a WebSocket connection to the backend on the given port.
     * Reconnects automatically on disconnect until disconnect() is called.
     *
     * @param {number} port
     */
    connect(port) {
        this._port = port;
        this._open();
    }

    _open() {
        const socket = new WebSocket(`ws://127.0.0.1:${this._port}/ws`);

        socket.onmessage = (event) => {
            try {
                const msg = JSON.parse(event.data);
                this.emit('backend-event', msg);
            } catch {
                // ignore malformed messages
            }
        };

        socket.onclose = () => {
            this._socket = null;
            if (this._port !== null) {
                setTimeout(() => this._open(), RECONNECT_DELAY);
            }
        };

        // onerror always fires before onclose; onclose handles reconnect
        socket.onerror = () => {};

        this._socket = socket;
    }

    /**
     * Closes the WebSocket connection and stops reconnection.
     */
    disconnect() {
        this._port = null;
        if (this._socket) {
            this._socket.close();
            this._socket = null;
        }
    }
}

module.exports = new WsClient();
