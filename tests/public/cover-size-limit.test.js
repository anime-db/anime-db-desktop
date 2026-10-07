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

// Loads the real cover-size-limit.js against a DOM shaped like the cover field of
// anime/edit.html.twig (issue #949).
require('../../app/assets/js/controller.js');

function setUpDom() {
    document.body.innerHTML = `
        <form>
            <div data-control="cover-size-limit">
                <input type="file" name="cover" data-max-bytes="100" data-too-large-message="Too large">
                <div data-cover-error hidden></div>
            </div>
        </form>
    `;
    jest.isolateModules(() => {
        require('../../app/assets/js/cover-size-limit.js');
    });
    document.body.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: document.body } }));
}

function choose(size) {
    const input = document.querySelector('input');
    Object.defineProperty(input, 'files', { configurable: true, value: size === null ? [] : [{ size }] });
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

function submit() {
    const event = new Event('submit', { bubbles: true, cancelable: true });
    document.querySelector('form').dispatchEvent(event);

    return event.defaultPrevented;
}

describe('cover-size-limit', () => {
    beforeEach(setUpDom);

    test('a file over the limit blocks the submit and shows the message', () => {
        choose(101);

        expect(submit()).toBe(true);
        const error = document.querySelector('[data-cover-error]');
        expect(error.hidden).toBe(false);
        expect(error.textContent).toBe('Too large');
    });

    test('a file within the limit does not block the submit', () => {
        choose(100);

        expect(submit()).toBe(false);
        expect(document.querySelector('[data-cover-error]').hidden).toBe(true);
    });

    test('choosing a smaller file or clearing the choice lifts the block', () => {
        choose(101);
        choose(10);
        expect(submit()).toBe(false);

        choose(101);
        choose(null);
        expect(submit()).toBe(false);
        expect(document.querySelector('[data-cover-error]').hidden).toBe(true);
    });
});
