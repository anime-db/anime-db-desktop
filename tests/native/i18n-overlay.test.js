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

const fs   = require('fs');
const os   = require('os');
const path = require('path');

// issue #646: resolveCatalog() merges the overlay written by the PHP core (<userData>/
// native-translations/<locale>.json, a separate task) with the built-in catalogs on a per-key
// basis. Each test gets its own overlay directory and a fresh copy of native/i18n (its module
// caches are per-process and never invalidated by design), so cache state never leaks between
// tests.
describe('resolveCatalog() overlay merge', () => {
    let overlayDir;
    let t;

    beforeEach(() => {
        overlayDir = fs.mkdtempSync(path.join(os.tmpdir(), 'native-translations-overlay-'));

        jest.resetModules();
        jest.doMock('../../native/paths', () => ({
            ...jest.requireActual('../../native/paths'),
            getNativeTranslationsOverlayDir: () => overlayDir,
        }));

        ({ t } = require('../../native/i18n'));
    });

    afterEach(() => {
        jest.dontMock('../../native/paths');
        fs.rmSync(overlayDir, { recursive: true, force: true });
    });

    test('overlay fills a key missing from the built-in catalog', () => {
        fs.writeFileSync(path.join(overlayDir, 'ru.json'), JSON.stringify({ 'plugin.only.key': 'Значение плагина' }));
        expect(t('plugin.only.key', 'ru')).toBe('Значение плагина');
    });

    test('built-in value wins over the overlay value for a locale the core ships', () => {
        fs.writeFileSync(path.join(overlayDir, 'ru.json'), JSON.stringify({ 'tray.open': 'Оверлей' }));
        expect(t('tray.open', 'ru')).toBe('Открыть');
    });

    test('a locale with no built-in catalog is served entirely from its overlay', () => {
        fs.writeFileSync(path.join(overlayDir, 'kk.json'), JSON.stringify({ 'tray.open': 'Ашу' }));
        expect(t('tray.open', 'kk')).toBe('Ашу');
    });

    // "kk" has no built-in catalog of its own; mapOsLocaleToAppLocale('kk') resolves the nearest
    // built-in locale to "ru" (issue #177), so a key the overlay does not cover falls back there.
    test('gaps in an overlay-only locale fall back through the nearest built-in locale', () => {
        fs.writeFileSync(path.join(overlayDir, 'kk.json'), JSON.stringify({ 'plugin.only.key': 'Ашу' }));
        expect(t('tray.open', 'kk')).toBe('Открыть');
    });

    // A non-string overlay value must not shadow the fallback chain either, for the same reason as
    // an empty one below: "kk" has no built-in catalog, so a kept (unfiltered) 42 would win the
    // per-key merge over the fallback's "Открыть" and only get caught by t()'s own totality check,
    // masking whether loadOverlay() actually filters the value or not.
    test('a non-string overlay value is dropped, the fallback chain is used instead', () => {
        fs.writeFileSync(path.join(overlayDir, 'kk.json'), JSON.stringify({ 'tray.open': 42 }));
        expect(t('tray.open', 'kk')).toBe('Открыть');
    });

    // An empty overlay value must not shadow the fallback chain with a blank label (issue #646):
    // "kk" has no built-in catalog, so without this the tray "Open" entry would render as "".
    test('an empty overlay value is dropped, the fallback chain is used instead', () => {
        fs.writeFileSync(path.join(overlayDir, 'kk.json'), JSON.stringify({ 'tray.open': '' }));
        expect(t('tray.open', 'kk')).toBe('Открыть');
    });

    test('a missing overlay file behaves exactly like no overlay at all', () => {
        expect(t('tray.open', 'ru')).toBe('Открыть');
    });

    test('invalid JSON in the overlay file does not throw and behaves like no overlay', () => {
        fs.writeFileSync(path.join(overlayDir, 'ru.json'), '{ not valid json');
        expect(() => t('tray.open', 'ru')).not.toThrow();
        expect(t('tray.open', 'ru')).toBe('Открыть');
    });

    test('the overlay is read once per process: a later write is not picked up', () => {
        expect(t('plugin.only.key', 'ru')).toBe('plugin.only.key');
        fs.writeFileSync(path.join(overlayDir, 'ru.json'), JSON.stringify({ 'plugin.only.key': 'Значение плагина' }));
        expect(t('plugin.only.key', 'ru')).toBe('plugin.only.key');
    });
});
