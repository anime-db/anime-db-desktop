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

// Exercises the "anime-type-change" control (issue #1001) against a minimal DOM shaped like the
// dialog templates/anime/_type_change_modal.html.twig renders for a series: "ova" loses nothing,
// "movie" drops data and needs the confirmation box.
require('../../app/assets/js/controller.js');

function setUpDom() {
    document.body.innerHTML = `
        <form data-control="anime-type-change">
            <select data-type-change-select>
                <option value="ova">OVA</option>
                <option value="movie">Movie</option>
            </select>
            <div data-type-change-loss="movie" hidden>loss</div>
            <div data-type-change-confirm hidden>
                <input type="checkbox" data-type-change-confirm-box>
            </div>
            <button type="submit" data-type-change-submit>Change</button>
        </form>
    `;
    jest.isolateModules(() => {
        require('../../app/assets/js/anime-type-change.js');
    });
    document.body.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: document.body } }));
}

function choose(value) {
    const select = document.querySelector('[data-type-change-select]');
    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

const loss = () => document.querySelector('[data-type-change-loss]');
const confirmBlock = () => document.querySelector('[data-type-change-confirm]');
const confirmBox = () => document.querySelector('[data-type-change-confirm-box]');
const submit = () => document.querySelector('[data-type-change-submit]');

describe('anime-type-change', () => {
    beforeEach(setUpDom);

    test('a change without loss needs no confirmation', () => {
        expect(loss().hidden).toBe(true);
        expect(confirmBlock().hidden).toBe(true);
        expect(submit().disabled).toBe(false);
    });

    test('a lossy change shows the loss and keeps the button disabled until the box is ticked', () => {
        choose('movie');

        expect(loss().hidden).toBe(false);
        expect(confirmBlock().hidden).toBe(false);
        expect(submit().disabled).toBe(true);

        confirmBox().checked = true;
        confirmBox().dispatchEvent(new Event('change', { bubbles: true }));
        expect(submit().disabled).toBe(false);
    });

    test('choosing another type resets the box and hides the loss', () => {
        choose('movie');
        confirmBox().checked = true;
        confirmBox().dispatchEvent(new Event('change', { bubbles: true }));

        choose('ova');
        expect(confirmBox().checked).toBe(false);
        expect(loss().hidden).toBe(true);
        expect(submit().disabled).toBe(false);

        choose('movie');
        expect(submit().disabled).toBe(true);
    });
});
