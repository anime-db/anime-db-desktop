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
// this module is a no-op, same as the window.animeDb guards in storage-new.js/anime-detail.js.
(function () {
    const container = document.getElementById('app-notifications');
    const template = document.getElementById('app-notification-template');

    if (!container || !template || !window.animeDb || !window.animeDb.onNotification) {
        return;
    }

    function showNotification({ title, message }) {
        const notification = template.content.firstElementChild.cloneNode(true);

        notification.querySelector('.app-notification__title').textContent = title;
        notification.querySelector('.app-notification__message').textContent = message;
        notification.querySelector('.app-notification__close').addEventListener('click', () => {
            notification.remove();
        });

        container.append(notification);
    }

    window.animeDb.onNotification(showNotification);
})();
