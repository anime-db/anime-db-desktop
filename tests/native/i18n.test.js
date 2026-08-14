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
const path = require('path');
const { t } = require('../../native/i18n');

const TRANSLATIONS_DIR = path.join(__dirname, '..', '..', 'native', 'translations');

describe('t()', () => {
    test('resolves a key for the exact locale', () => {
        expect(t('tray.open', 'ru')).toBe('Открыть');
        expect(t('tray.open', 'en')).toBe('Open');
    });

    test('substitutes %name% placeholders from params', () => {
        expect(t('dialog.migration_detail_error', 'ru', { detail: 'boom' })).toBe('Ошибка: boom');
        expect(t('dialog.migration_detail_error', 'en', { detail: 'boom' })).toBe('Error: boom');
    });

    test('returns the key unchanged when it is missing from the catalog', () => {
        expect(t('does.not.exist', 'ru')).toBe('does.not.exist');
    });

    // issue #404: a locale supplied by a translation plugin (e.g. "kk") has a window catalog but
    // no native/translations/<locale>.json — the fallback goes through mapOsLocaleToAppLocale(),
    // not straight to "en", because for post-Soviet locales the nearer language is Russian
    // (issue #177).
    describe('fallback for a locale with no native catalog', () => {
        test.each([
            ['kk', 'Открыть'],
            ['kk-KZ', 'Открыть'],
            ['be', 'Открыть'],
        ])('%s falls back to Russian', (locale, expected) => {
            expect(t('tray.open', locale)).toBe(expected);
        });

        test.each([
            ['de', 'Open'],
            ['uk', 'Open'],
            ['fr-FR', 'Open'],
        ])('%s falls back to English', (locale, expected) => {
            expect(t('tray.open', locale)).toBe(expected);
        });
    });
});

describe('native translation catalogs', () => {
    test('ru.json and en.json declare exactly the same set of keys', () => {
        const ru = JSON.parse(fs.readFileSync(path.join(TRANSLATIONS_DIR, 'ru.json'), 'utf8'));
        const en = JSON.parse(fs.readFileSync(path.join(TRANSLATIONS_DIR, 'en.json'), 'utf8'));

        expect(Object.keys(ru).sort()).toEqual(Object.keys(en).sort());
    });

    // A typo or rename in a splash.step_* key would still pass every other test, since t()
    // deliberately falls back to returning the key unchanged instead of throwing. Without this
    // check, such a mismatch between the emitter (supervisor/index.js) and the catalog would only
    // surface as a raw key shown on the splash screen.
    describe('splash step keys resolve to translated text', () => {
        const SPLASH_STEP_KEYS = [
            'splash.step_meilisearch',
            'splash.step_migrations',
            'splash.step_frankenphp',
            'splash.step_messenger',
            'splash.step_reindex',
            'splash.step_done',
        ];

        test.each(SPLASH_STEP_KEYS)('%s is translated on both locales', (key) => {
            expect(t(key, 'ru')).not.toBe(key);
            expect(t(key, 'en')).not.toBe(key);
        });
    });
});
