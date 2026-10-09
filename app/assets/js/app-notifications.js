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

// Listens for native → renderer push notifications delivered through window.animeDb.onNotification()
// (native/window/preload.js). The only source today is a plugin-activation-failed event emitted by
// the Electron main process (issue #417), already localized there via native/i18n before it is
// sent, but the container/template and delivery channel are generic so a future in-app notification
// history/list can reuse them without changing this file. Outside Electron (window.animeDb absent),
// mounting is a no-op, same as the window.animeDb guards in storage-new.js/anime-detail.js.
(function () {
    function renderNotification(container, { title, message }) {
        const template = document.getElementById('app-notification-template');

        if (!template) {
            return;
        }

        const notification = template.content.firstElementChild.cloneNode(true);
        const messageElement = notification.querySelector('.app-notification__message');

        notification.querySelector('.app-notification__title').textContent = title;
        if (message) {
            messageElement.textContent = message;
        } else {
            messageElement.remove();
        }
        notification.querySelector('.app-notification__close').addEventListener('click', () => {
            notification.remove();
        });

        container.append(notification);
    }

    function mountAppNotifications(container) {
        if (!document.getElementById('app-notification-template') || !window.animeDb || !window.animeDb.onNotification) {
            return;
        }

        window.animeDb.onNotification((payload) => renderNotification(container, payload));
    }

    // One global handler for htmx requests that failed unexpectedly (5xx, 4xx such as an expired
    // CSRF token, or no response at all): htmx leaves the fragment untouched in all of these cases,
    // so without it the user would assume the action was saved. Fragments with their own expected
    // 4xx handling swap the response themselves and never reach htmx:responseError.
    function notifyRequestFailed() {
        const container = document.getElementById('app-notifications');

        if (container && container.dataset.requestError) {
            renderNotification(container, { title: container.dataset.requestError });
        }
    }

    // A re-evaluation of this module (the test suite loads it once per test) must not stack a second
    // listener on the same document, or one failure would render several notifications.
    if (window.appNotificationsRequestFailedHandler) {
        document.removeEventListener('htmx:responseError', window.appNotificationsRequestFailedHandler);
        document.removeEventListener('htmx:sendError', window.appNotificationsRequestFailedHandler);
    }
    window.appNotificationsRequestFailedHandler = notifyRequestFailed;
    document.addEventListener('htmx:responseError', notifyRequestFailed);
    document.addEventListener('htmx:sendError', notifyRequestFailed);

    window.Controller.registerControl('app-notifications', mountAppNotifications);
})();
