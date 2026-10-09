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

// inline-handlers.js restores focus once, when it loads on the page that follows the submit, so
// every case loads a fresh copy of the module (jest.isolateModules) after preparing the DOM and
// sessionStorage the way the previous page left them.
function loadModule() {
    jest.isolateModules(() => {
        require('../../app/assets/js/inline-handlers.js');
    });
}

beforeEach(() => {
    window.sessionStorage.clear();
    document.body.innerHTML = `
        <form action="/a"><input type="radio" id="a-light" name="theme" value="light" data-submit-on-change></form>
        <form action="/a"><input type="radio" id="a-dark" name="theme" value="dark" data-submit-on-change></form>
    `;
});

test('focuses the control remembered before the reload and clears the record', () => {
    window.sessionStorage.setItem('submit-on-change-focus', JSON.stringify({ name: 'theme', value: 'dark' }));

    loadModule();

    expect(document.activeElement).toBe(document.getElementById('a-dark'));
    expect(window.sessionStorage.getItem('submit-on-change-focus')).toBeNull();
});

test('leaves focus alone when nothing was remembered', () => {
    loadModule();

    expect(document.activeElement).toBe(document.body);
});

test('clears a record that matches no control', () => {
    window.sessionStorage.setItem('submit-on-change-focus', JSON.stringify({ name: 'gone', value: 'x' }));

    loadModule();

    expect(document.activeElement).toBe(document.body);
    expect(window.sessionStorage.getItem('submit-on-change-focus')).toBeNull();
});

test('ignores a corrupt record', () => {
    window.sessionStorage.setItem('submit-on-change-focus', '{not json');

    loadModule();

    expect(document.activeElement).toBe(document.body);
    expect(window.sessionStorage.getItem('submit-on-change-focus')).toBeNull();
});

test('picks the control in the form whose action was remembered when name and value repeat', () => {
    document.body.innerHTML = `
        <form action="/widgets/1"><input type="checkbox" id="w1" name="active" value="1" data-submit-on-change></form>
        <form action="/widgets/2"><input type="checkbox" id="w2" name="active" value="1" data-submit-on-change></form>
    `;
    window.sessionStorage.setItem(
        'submit-on-change-focus',
        JSON.stringify({ name: 'active', value: '1', action: '/widgets/2' }),
    );

    loadModule();

    expect(document.activeElement).toBe(document.getElementById('w2'));
});

test('remembers the action on change and restores focus to that very control after the reload', () => {
    document.body.innerHTML = `
        <form action="/widgets/1"><input type="checkbox" id="w1" name="active" value="1" data-submit-on-change></form>
        <form action="/widgets/2"><input type="checkbox" id="w2" name="active" value="1" data-submit-on-change></form>
    `;
    document.querySelectorAll('form').forEach((form) => {
        form.submit = jest.fn();
    });
    loadModule();

    const second = document.getElementById('w2');
    second.checked = true;
    second.dispatchEvent(new Event('change', { bubbles: true }));

    expect(JSON.parse(window.sessionStorage.getItem('submit-on-change-focus'))).toEqual({
        name: 'active', value: '1', action: '/widgets/2',
    });

    document.getElementById('w1').focus();
    loadModule();

    expect(document.activeElement).toBe(second);
});
