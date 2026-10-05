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

// Loads the real choice-filter.js against a DOM shaped like the studios block of
// anime/edit.html.twig (issue #914): typing hides non-matching rows without touching checkboxes.
require('../../app/assets/js/controller.js');

function setUpDom() {
    document.body.innerHTML = `
        <div data-control="choice-filter">
            <input data-filter-input>
            <label data-filter-item data-filter-text="Bones"><input type="checkbox" name="studios[]" value="1" checked></label>
            <label data-filter-item data-filter-text="Madhouse"><input type="checkbox" name="studios[]" value="2"></label>
        </div>
    `;
    jest.isolateModules(() => {
        require('../../app/assets/js/choice-filter.js');
    });
    document.body.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: document.body } }));
}

function type(value) {
    const input = document.querySelector('[data-filter-input]');
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

function hiddenItems() {
    return Array.from(document.querySelectorAll('[data-filter-item]')).map((item) => item.hidden);
}

describe('choice-filter', () => {
    beforeEach(setUpDom);

    test('hides rows that do not match, case-insensitively', () => {
        type('MAD');
        expect(hiddenItems()).toEqual([true, false]);
    });

    test('an empty query shows every row again', () => {
        type('mad');
        type('  ');
        expect(hiddenItems()).toEqual([false, false]);
    });

    test('hidden rows keep their checked state', () => {
        type('mad');
        expect(document.querySelector('input[value="1"]').checked).toBe(true);
    });
});
