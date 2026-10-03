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

// Top-nav "Add" menu (issue #834): the "Scan" section lists every connected, scannable storage,
// which needs an is_readable() filesystem check per storage — on a network path that can be slow,
// and this menu is part of base.html.twig, rendered on every page. So that section alone is
// fetched lazily, the first time the menu is opened on this page, instead of synchronously with
// the rest of the header.
(function () {
    function mountNavAddMenu(root) {
        const toggle = root.querySelector('[data-bs-toggle="dropdown"]');
        const section = root.querySelector('[data-nav-scan-section]');
        const url = root.dataset.scanSectionUrl;

        let loaded = false;

        function onShow() {
            // "Once per page" (issue #834): a second show.bs.dropdown on the same mount — closing
            // and reopening the menu without navigating away — must not fire a second request.
            if (loaded) {
                return;
            }
            loaded = true;

            fetch(url)
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`Scan section request failed with status ${response.status}`);
                    }

                    return response.text();
                })
                .then((html) => {
                    section.outerHTML = html;
                })
                .catch(() => {
                    section.outerHTML = '';
                })
                .finally(() => {
                    // The menu's height just changed (the spinner row was replaced by a, usually
                    // taller, list of storages) — Popper needs to recompute its position.
                    window.bootstrap.Dropdown.getOrCreateInstance(toggle).update();
                });
        }

        toggle.addEventListener('show.bs.dropdown', onShow);

        return function unmountNavAddMenu() {
            toggle.removeEventListener('show.bs.dropdown', onShow);
        };
    }

    window.Controller.registerControl('nav-add-menu', mountNavAddMenu);
})();
