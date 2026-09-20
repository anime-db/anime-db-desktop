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

// translations.js is the single implementation of %name% substitution shared by every JS caller
// (anime-list.js, backup.js, storage-scan.js — issue #677). These tests pin the one behaviour that
// used to diverge between the three now-removed copies: a placeholder repeated inside a single
// string must be substituted at every occurrence, not just the first.

function jsonResponse(body) {
    return { ok: true, status: 200, json: () => Promise.resolve(body) };
}

function loadTranslationsModule() {
    jest.isolateModules(() => {
        require('../../app/public/js/translations.js');
    });
}

beforeEach(() => {
    jest.resetModules();
    document.documentElement.lang = 'ru';
});

afterEach(() => {
    delete global.fetch;
    delete window.AppTranslations;
});

test('resolveKey() substitutes every occurrence of a repeated placeholder', () => {
    loadTranslationsModule();

    const messages = { 'test.repeat': '%name% and %name% again' };
    const result = window.AppTranslations.resolveKey(messages, 'test.repeat', { name: 'X' });

    expect(result).toBe('⁨X⁩ and ⁨X⁩ again');
});

test('trans() substitutes every occurrence of a repeated placeholder via the fetched catalogue', async () => {
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse({ 'test.repeat': '%name% and %name% again' })));
    loadTranslationsModule();

    const result = await window.AppTranslations.trans('test.repeat', { name: 'X' });

    expect(result).toBe('⁨X⁩ and ⁨X⁩ again');
    expect(global.fetch).toHaveBeenCalledWith('/translations/ru.json');
});

// Every substituted value is bidi-isolated (issue #450), regardless of caller — moved here from
// storage-scan.js's now-removed local isolate() helper so every consumer of trans()/resolveKey()
// (backup.js, storage-scan.js, anime-list.js) gets the same protection without opting in (issue
// #677).
test('resolveKey() wraps every substituted value in bidi isolate marks', () => {
    loadTranslationsModule();

    const messages = { 'test.path': 'Archive at %path%' };
    const result = window.AppTranslations.resolveKey(messages, 'test.path', { path: 'C:\\Users\\a' });

    expect(result).toBe('Archive at ⁨C:\\Users\\a⁩');
});

test('resolveKey() and trans() agree on a repeated placeholder for the same catalogue entry', async () => {
    const messages = { 'test.repeat': '%name%/%name%' };
    global.fetch = jest.fn(() => Promise.resolve(jsonResponse(messages)));
    loadTranslationsModule();

    const viaTrans = await window.AppTranslations.trans('test.repeat', { name: 'Y' });
    const viaResolveKey = window.AppTranslations.resolveKey(messages, 'test.repeat', { name: 'Y' });

    expect(viaTrans).toBe(viaResolveKey);
});
