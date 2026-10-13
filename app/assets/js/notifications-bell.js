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

// Header bell of the notification history (issue #1025). The initial count is rendered from the
// database with every page; between navigations it is refreshed from the unread-count IPC channel
// (window.animeDb.onUnreadCount(), native/window/preload.js), which carries only the number —
// no toast is drawn from it (that is what the 'app-notification' channel is for). Outside Electron
// (window.animeDb absent) the subscription is skipped, same as in app-notifications.js. The page
// opens no WebSocket of its own.
//
// Opening the menu fetches the list fragment and marks exactly the records it showed as read.
(function () {
    function mountNotificationsBell(root) {
        const badge = root.querySelector('[data-bell-badge]');
        const list = root.querySelector('[data-bell-list]');
        const button = root.querySelector('button');

        function setCount(count) {
            const number = Number(count);

            if (!Number.isFinite(number) || number <= 0) {
                badge.textContent = '0';
                badge.hidden = true;
                button.setAttribute('aria-label', root.dataset.label);

                return;
            }
            const unread = String(Math.floor(number));

            badge.textContent = unread;
            badge.hidden = false;
            button.setAttribute('aria-label', root.dataset.labelUnread.replace('%count%', unread));
        }

        function post(url, params) {
            const body = new URLSearchParams(params);

            body.set('_token', root.dataset.csrfToken);

            return fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body,
            }).then((response) => {
                if (!response.ok) {
                    throw new Error(`Notifications request failed with status ${response.status}`);
                }

                return response.json();
            });
        }

        function markShownRead() {
            const ids = Array.from(list.querySelectorAll('[data-notification-unread]'))
                .map((item) => item.dataset.notificationId);

            if (ids.length === 0) {
                return Promise.resolve();
            }

            return post(root.dataset.markReadUrl, ids.map((id) => ['ids[]', id]))
                .then((data) => setCount(data.unread_count));
        }

        function onShow() {
            fetch(root.dataset.listUrl)
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`Notifications list request failed with status ${response.status}`);
                    }

                    return response.text();
                })
                .then((html) => {
                    list.innerHTML = html;

                    // A failed mark is not a failed load: the shown list stays, the records stay unread.
                    markShownRead().catch(() => {});
                })
                .catch(() => {
                    list.textContent = list.dataset.error;
                });
        }

        function onClick(event) {
            if (!event.target.closest('[data-bell-read-all]')) {
                return;
            }

            post(root.dataset.readAllUrl, [])
                .then((data) => {
                    setCount(data.unread_count);
                    list.querySelectorAll('[data-notification-unread]').forEach((item) => {
                        item.removeAttribute('data-notification-unread');
                        item.classList.remove('notification-item--unread');
                    });
                })
                .catch(() => {});
        }

        root.addEventListener('show.bs.dropdown', onShow);
        root.addEventListener('click', onClick);

        if (window.animeDb && window.animeDb.onUnreadCount) {
            window.animeDb.onUnreadCount(setCount);
        }

        return function unmountNotificationsBell() {
            root.removeEventListener('show.bs.dropdown', onShow);
            root.removeEventListener('click', onClick);
        };
    }

    window.Controller.registerControl('notifications-bell', mountNotificationsBell);
})();
