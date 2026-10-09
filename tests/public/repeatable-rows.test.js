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

// Loads the real repeatable-rows.js against a DOM shaped like a list of anime/edit.html.twig
// (issue #914): add appends a row from the template with a fresh index, remove drops just that row.
require('../../app/assets/js/controller.js');
require('../../app/assets/js/focus-restore.js');

function mountControls(root = document.body) {
    root.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: root } }));
}

function setUpDom() {
    document.body.innerHTML = `
        <div data-control="repeatable-rows">
            <div data-rows-list>
                <div data-rows-item><input name="sources[0]" value="https://a.example/"><button type="button" data-rows-remove></button></div>
                <div data-rows-item><input name="sources[1]" value="https://b.example/"><button type="button" data-rows-remove></button></div>
            </div>
            <template data-rows-template>
                <div data-rows-item><input name="sources[__INDEX__]" value=""><button type="button" data-rows-remove></button></div>
            </template>
            <button type="button" data-rows-add></button>
        </div>
    `;
    jest.isolateModules(() => {
        require('../../app/assets/js/repeatable-rows.js');
    });
    mountControls();
}

function names() {
    return Array.from(document.querySelectorAll('[data-rows-list] input')).map((input) => input.name);
}

describe('repeatable-rows', () => {
    beforeEach(setUpDom);

    test('add appends a row with an index not used by the existing rows', () => {
        document.querySelector('[data-rows-add]').click();

        expect(names()).toEqual(['sources[0]', 'sources[1]', 'sources[2]']);
    });

    test('remove drops only its own row and an added row never reuses a removed index', () => {
        document.querySelectorAll('[data-rows-remove]')[0].click();
        document.querySelector('[data-rows-add]').click();

        expect(names()).toEqual(['sources[1]', 'sources[2]']);
    });
});

describe('repeatable-rows focus after removal', () => {
    beforeEach(setUpDom);

    test('focus moves to the field of the neighbouring row', () => {
        const removeButtons = document.querySelectorAll('[data-rows-remove]');
        removeButtons[0].focus();
        removeButtons[0].click();

        expect(document.activeElement).toBe(document.querySelector('input[name="sources[1]"]'));
    });

    test('focus moves to the Add button once no rows are left', () => {
        document.querySelectorAll('[data-rows-remove]')[0].click();
        const last = document.querySelector('[data-rows-remove]');
        last.focus();
        last.click();

        expect(document.querySelectorAll('[data-rows-item]')).toHaveLength(0);
        expect(document.activeElement).toBe(document.querySelector('[data-rows-add]'));
    });
});
