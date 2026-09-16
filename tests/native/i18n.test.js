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

jest.mock('../../native/paths', () => ({
    getNativeTranslationsDir:        () => require('path').join(__dirname, '..', '..', 'native', 'translations'),
    getNativeTranslationsOverlayDir: () => '/fake/does-not-exist/native-translations',
}));

const fs   = require('fs');
const path = require('path');
const { t, textDirection } = require('../../native/i18n');
const { extractPlaceholders, findForbiddenCharacters, diffPlaceholders } = require('./i18n-placeholder-parity');

const TRANSLATIONS_DIR = path.join(__dirname, '..', '..', 'native', 'translations');

/**
 * @returns {Record<string, Record<string, string>>} locale -> catalog, for every
 * native/translations/<locale>.json file found on disk
 */
function loadCatalogs() {
    return Object.fromEntries(
        fs.readdirSync(TRANSLATIONS_DIR)
            .filter((file) => file.endsWith('.json'))
            .map((file) => [
                path.basename(file, '.json'),
                JSON.parse(fs.readFileSync(path.join(TRANSLATIONS_DIR, file), 'utf8')),
            ]),
    );
}

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

        // t() обслуживает в том числе диалоги ошибок запуска и обработчик uncaughtException, где
        // исключение из слоя переводов заменило бы сообщение об ошибке отсутствием сообщения.
        test.each([
            [undefined],
            [null],
            [''],
        ])('a non-string locale (%p) falls back to English instead of throwing', (locale) => {
            expect(() => t('tray.open', locale)).not.toThrow();
            expect(t('tray.open', locale)).toBe('Open');
        });
    });
});

// issue #450: mirrors App\Service\LocaleDirection on the PHP side — direction is resolved from a
// static core table keyed on the locale's language subtag, not from anything a translation
// plugin declares.
describe('textDirection()', () => {
    test.each([
        ['ar', 'rtl'],
        ['ar-EG', 'rtl'],
        ['he', 'rtl'],
        ['fa', 'rtl'],
        ['ur', 'rtl'],
    ])('%s resolves to "rtl"', (locale, expected) => {
        expect(textDirection(locale)).toBe(expected);
    });

    test.each([
        ['ru', 'ltr'],
        ['en', 'ltr'],
        ['de', 'ltr'],
        ['ja', 'ltr'],
        ['xx', 'ltr'],
        ['', 'ltr'],
    ])('%s resolves to "ltr"', (locale, expected) => {
        expect(textDirection(locale)).toBe(expected);
    });

    test.each([
        [undefined],
        [null],
    ])('a non-string locale (%p) resolves to "ltr" instead of throwing', (locale) => {
        expect(() => textDirection(locale)).not.toThrow();
        expect(textDirection(locale)).toBe('ltr');
    });
});

describe('native translation catalogs', () => {
    test('ru.json and en.json declare exactly the same set of keys', () => {
        const ru = JSON.parse(fs.readFileSync(path.join(TRANSLATIONS_DIR, 'ru.json'), 'utf8'));
        const en = JSON.parse(fs.readFileSync(path.join(TRANSLATIONS_DIR, 'en.json'), 'utf8'));

        expect(Object.keys(ru).sort()).toEqual(Object.keys(en).sort());
    });

    // Key parity (above) does not catch the nastiest translation defect: the key is present in
    // both locales, but the %placeholder% inside its value got lost or renamed. Both tests are
    // green, the string is broken - the user sees an error dialog with the placeholder text
    // missing instead of the actual error.
    describe('placeholder parity across locale catalogs', () => {
        test('%name% placeholders match for every key shared between locales', () => {
            const catalogs = loadCatalogs();
            const locales  = Object.keys(catalogs).sort();
            const [reference, ...rest] = locales;

            const failures = [];
            for (const locale of rest) {
                for (const [key, referenceValue] of Object.entries(catalogs[reference])) {
                    if (!(key in catalogs[locale])) {
                        continue; // key parity is covered by the test above, not here
                    }

                    const diff = diffPlaceholders(referenceValue, catalogs[locale][key]);
                    if (diff !== null) {
                        failures.push(
                            `${key} (${reference} vs ${locale}): missing [${diff.missing.join(', ')}], `
                            + `extra [${diff.extra.join(', ')}]`,
                        );
                    }
                }
            }

            expect(failures).toEqual([]);
        });

        test('catalog values do not use reserved "{", "}" or "|" syntax', () => {
            const catalogs = loadCatalogs();

            const failures = [];
            for (const [locale, catalog] of Object.entries(catalogs)) {
                for (const [key, value] of Object.entries(catalog)) {
                    const forbidden = findForbiddenCharacters(value);
                    if (forbidden.length > 0) {
                        failures.push(`${locale}.json key "${key}" uses reserved syntax "${forbidden.join('", "')}"`);
                    }
                }
            }

            expect(failures).toEqual([]);
        });
    });

    describe('diffPlaceholders()', () => {
        test('returns null when the placeholder sets match', () => {
            expect(diffPlaceholders('Error: %detail%', 'Ошибка: %detail%')).toBeNull();
        });

        test('ignores placeholder order', () => {
            expect(diffPlaceholders('%a% and %b%', '%b% and %a%')).toBeNull();
        });

        test('reports a lost placeholder', () => {
            expect(diffPlaceholders('Error: %detail%', 'Ошибка: ')).toEqual({ missing: ['detail'], extra: [] });
        });

        test('reports a renamed placeholder in both directions', () => {
            expect(diffPlaceholders('Error: %detail%', 'Ошибка: %reason%'))
                .toEqual({ missing: ['detail'], extra: ['reason'] });
        });
    });

    describe('extractPlaceholders()', () => {
        test('deduplicates repeated placeholders', () => {
            expect(extractPlaceholders('%total% of %total%')).toEqual(['total']);
        });
    });

    describe('findForbiddenCharacters()', () => {
        test('detects curly braces', () => {
            expect(findForbiddenCharacters('Hello {name}')).toEqual(['{', '}']);
        });

        test('detects a pipe', () => {
            expect(findForbiddenCharacters('one item|many items')).toEqual(['|']);
        });

        test('is empty for a plain placeholder', () => {
            expect(findForbiddenCharacters('Error: %detail%')).toEqual([]);
        });
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
