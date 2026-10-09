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

// The "Change type…" dialog of the entry card (issue #1001, templates/anime/_type_change_modal.html.twig).
// A type whose change drops data (a series becoming a movie) has a [data-type-change-loss] block with
// the dropped values; choosing it reveals the block and the confirmation box, and the submit button
// stays disabled until the box is ticked. Choosing another type resets the box, so one tick never
// covers a type the user has not seen the loss of.
(function () {
    function mountAnimeTypeChange(form) {
        const select = form.querySelector('[data-type-change-select]');
        const confirmBlock = form.querySelector('[data-type-change-confirm]');
        const confirmBox = form.querySelector('[data-type-change-confirm-box]');
        const submit = form.querySelector('[data-type-change-submit]');
        if (!select || !confirmBlock || !confirmBox || !submit) {
            return;
        }

        function sync() {
            let lossy = false;
            form.querySelectorAll('[data-type-change-loss]').forEach((block) => {
                const shown = block.dataset.typeChangeLoss === select.value;
                block.hidden = !shown;
                lossy = lossy || shown;
            });

            confirmBlock.hidden = !lossy;
            submit.disabled = lossy && !confirmBox.checked;
        }

        select.addEventListener('change', () => {
            confirmBox.checked = false;
            sync();
        });
        confirmBox.addEventListener('change', sync);

        sync();
    }

    window.Controller.registerControl('anime-type-change', mountAnimeTypeChange);
})();
