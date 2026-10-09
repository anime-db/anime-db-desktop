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

require('../../app/assets/js/controller.js');

test('storage-edit toggles required on the path field by type', () => {
    document.body.innerHTML = `
        <form data-control="storage-edit">
            <select id="storage-edit-type" data-path-optional-types="video,external-r">
                <option value="folder">folder</option>
                <option value="video">video</option>
            </select>
            <input type="text" id="storage-edit-path" required>
        </form>
    `;
    jest.isolateModules(() => {
        require('../../app/assets/js/storage-edit.js');
    });
    document.body.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: document.body } }));

    const select = document.getElementById('storage-edit-type');
    const path = document.getElementById('storage-edit-path');
    expect(path.required).toBe(true);

    select.value = 'video';
    select.dispatchEvent(new Event('change'));
    expect(path.required).toBe(false);

    select.value = 'folder';
    select.dispatchEvent(new Event('change'));
    expect(path.required).toBe(true);
});

test('storage-edit disables and clears the path for a not-applicable type and restores it on return', () => {
    document.body.innerHTML = `
        <form data-control="storage-edit">
            <select id="storage-edit-type" data-path-not-applicable-types="video">
                <option value="external-r">external-r</option>
                <option value="video">video</option>
            </select>
            <input type="text" id="storage-edit-path" value="D:\\Discs">
            <p id="storage-edit-path-not-applicable" hidden></p>
        </form>
    `;
    jest.isolateModules(() => {
        require('../../app/assets/js/storage-edit.js');
    });
    document.body.dispatchEvent(new CustomEvent('htmx:load', { bubbles: true, detail: { elt: document.body } }));

    const select = document.getElementById('storage-edit-type');
    const path = document.getElementById('storage-edit-path');
    const hint = document.getElementById('storage-edit-path-not-applicable');
    expect(path.disabled).toBe(false);
    expect(hint.hidden).toBe(true);

    select.value = 'video';
    select.dispatchEvent(new Event('change'));
    expect(path.disabled).toBe(true);
    expect(path.value).toBe('');
    expect(hint.hidden).toBe(false);

    select.value = 'external-r';
    select.dispatchEvent(new Event('change'));
    expect(path.disabled).toBe(false);
    expect(path.value).toBe('D:\\Discs');
    expect(hint.hidden).toBe(true);
});
