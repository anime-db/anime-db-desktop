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

// The shared "keep keyboard focus across a re-render" helper (issue #1010).
require('../../app/assets/js/focus-restore.js');

function setUpDom() {
    document.body.innerHTML = `
        <button id="outside">Outside</button>
        <div id="box">
            <button data-focus-key="a">A</button>
            <button data-focus-key="b">B</button>
            <button>Unkeyed</button>
        </div>
        <button id="fallback">Fallback</button>
    `;
}

function rebuild(keys) {
    return () => {
        document.getElementById('box').innerHTML = keys
            .map((key) => (key === null ? '<button>Unkeyed</button>' : `<button data-focus-key="${key}">${key}</button>`))
            .join('');
    };
}

beforeEach(setUpDom);

test('focus returns to the element with the same key in the rebuilt content', () => {
    const box = document.getElementById('box');
    box.querySelector('[data-focus-key="b"]').focus();

    window.FocusRestore.run(box, rebuild(['a', 'b']));

    expect(document.activeElement).toBe(box.querySelector('[data-focus-key="b"]'));
});

test('focus goes to the fallback element when the key is gone', () => {
    const box = document.getElementById('box');
    box.querySelector('[data-focus-key="b"]').focus();

    window.FocusRestore.run(box, rebuild(['a']), document.getElementById('fallback'));

    expect(document.activeElement).toBe(document.getElementById('fallback'));
});

test('a fallback function is called after the rebuild', () => {
    const box = document.getElementById('box');
    box.querySelector('[data-focus-key="b"]').focus();

    window.FocusRestore.run(box, rebuild(['a']), () => box.querySelector('[data-focus-key="a"]'));

    expect(document.activeElement).toBe(box.querySelector('[data-focus-key="a"]'));
});

test('focus outside the container is left alone', () => {
    const outside = document.getElementById('outside');
    outside.focus();

    window.FocusRestore.run(document.getElementById('box'), rebuild(['a']), document.getElementById('fallback'));

    expect(document.activeElement).toBe(outside);
});

test('a focused element without a key falls back', () => {
    const box = document.getElementById('box');
    box.querySelector('button:not([data-focus-key])').focus();

    window.FocusRestore.run(box, rebuild(['a', null]), document.getElementById('fallback'));

    expect(document.activeElement).toBe(document.getElementById('fallback'));
});

test('without a fallback, a vanished key leaves focus where the browser put it', () => {
    const box = document.getElementById('box');
    box.querySelector('[data-focus-key="b"]').focus();

    window.FocusRestore.run(box, rebuild(['a']));

    expect(document.activeElement).toBe(document.body);
});
