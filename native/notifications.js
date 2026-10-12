/**
 * AnimeDB package.
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

/**
 * Backend event name published when a notification is recorded. Keep in sync with the PHP side.
 */
const NOTIFICATION_CREATED_EVENT = 'notification.created';

/** IPC channel carrying the unread counter to the renderer (separate from 'app-notification'). */
const UNREAD_COUNT_CHANNEL = 'app-notification-unread-count';

/** Upper bound of remembered (id, occurrences) pairs; the oldest pair is evicted first. */
const MAX_SHOWN_PAIRS = 500;

/**
 * Remembers recently shown (id, occurrences) pairs. A replayed event (WS reconnect lookback) has
 * the same pair, while a coalesced record keeps its id and grows occurrences, so it counts as new.
 *
 * @param {number} [limit]
 * @returns {{ markShown: (id: number|string, occurrences: number) => boolean }}
 */
function createShownTracker(limit = MAX_SHOWN_PAIRS) {
    const shown = new Set();

    return {
        /**
         * @returns {boolean} true when the pair was not seen before (and is now remembered)
         */
        markShown(id, occurrences) {
            const key = `${id}:${occurrences}`;
            if (shown.has(key)) return false;
            shown.add(key);
            if (shown.size > limit) shown.delete(shown.values().next().value);
            return true;
        },
    };
}

/**
 * Single decision point between an OS toast and an in-app popup for 'notification.created'.
 *
 * @param {object} deps
 * @param {() => (import('electron').BrowserWindow | null)} deps.getWindow
 * @param {typeof import('electron').Notification} deps.Notification
 * @param {() => boolean} deps.isSystemNotificationsEnabled
 * @param {(url: string|null) => void} deps.onToastClick shows the window and opens the url
 * @returns {{ handle: (data: Record<string, any>) => void }}
 */
function createNotificationDelivery({ getWindow, Notification, isSystemNotificationsEnabled, onToastClick }) {
    const tracker = createShownTracker();
    // Strong references: a toast garbage-collected before the click would lose its handler.
    const liveToasts = new Set();

    return {
        handle(data) {
            const win = getWindow();
            const alive = win !== null && win !== undefined && !win.isDestroyed();

            // The counter is sent for every event, regardless of focus and dedup.
            if (alive && data.unread_count !== undefined) {
                win.webContents.send(UNREAD_COUNT_CHANNEL, data.unread_count);
            }

            if (!tracker.markShown(data.id, data.occurrences)) return;

            const focused = alive && win.isVisible() && !win.isMinimized() && win.isFocused();
            if (focused) {
                win.webContents.send('app-notification', {
                    type:    'notification',
                    title:   data.title,
                    message: data.body,
                });

                return;
            }

            if (!isSystemNotificationsEnabled() || !Notification.isSupported()) return;

            const toast = new Notification({ title: data.title, body: data.body });
            liveToasts.add(toast);
            toast.on('click', () => {
                liveToasts.delete(toast);
                onToastClick(data.url ?? null);
            });
            toast.on('close', () => liveToasts.delete(toast));
            toast.show();
        },
    };
}

module.exports = {
    NOTIFICATION_CREATED_EVENT,
    UNREAD_COUNT_CHANNEL,
    createShownTracker,
    createNotificationDelivery,
};
