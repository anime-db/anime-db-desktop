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

const fs = require('fs');
const os = require('os');
const path = require('path');

const { copyVendorScripts, compileStyles } = require('../../scripts/build-assets');

// Реальная компиляция Bootstrap, без моков: смысл этих проверок именно в том, что из
// исходников получается CSS с нужными значениями, а замоканный sass об этом ничего не скажет.
const COMPILE_TIMEOUT_MS = 60000;

function tempDir(prefix) {
    return fs.mkdtempSync(path.join(os.tmpdir(), prefix));
}

describe('copyVendorScripts', () => {
    test('puts htmx and the Bootstrap bundle next to the application scripts', () => {
        const outDir = tempDir('vendor-scripts-');

        copyVendorScripts(outDir);

        expect(fs.existsSync(path.join(outDir, 'htmx.min.js'))).toBe(true);
        expect(fs.existsSync(path.join(outDir, 'bootstrap.bundle.min.js'))).toBe(true);
    });
});

describe('compileStyles', () => {
    let css;
    let rtlCss;

    beforeAll(() => {
        const outDir = tempDir('styles-');
        compileStyles(undefined, outDir);
        css = fs.readFileSync(path.join(outDir, 'app.css'), 'utf8');
        rtlCss = fs.readFileSync(path.join(outDir, 'app.rtl.css'), 'utf8');
    }, COMPILE_TIMEOUT_MS);

    test('themes Bootstrap with the project palette', () => {
        // #db3400, а не брендовый #fc6703: под белым текстом брендовый даёт 2.97:1 и
        // проваливает WCAG AA — см. комментарий в app/assets/scss/_palette.scss.
        expect(css).toContain('--bs-btn-bg: #db3400');
        expect(css).toContain('--bs-btn-color: #fff');
    });

    test('exposes the brand accent as an application token', () => {
        expect(css).toContain('--adb-accent: #fc6703');
    });

    test('carries the dark colour mode', () => {
        expect(css).toContain('[data-bs-theme=dark]');
    });

    test('keeps application styles free of hardcoded colours', () => {
        expect(css).toContain('.app-nav__link--active{box-shadow:inset 0 -3px 0 var(--adb-accent)');
        expect(css).toContain('background:var(--bs-danger-bg-subtle)');
    });

    test('carries every application stylesheet, not just the globally shared ones', () => {
        // Стили страниц собираются в тот же бандл (issue #616): постраничных .css больше нет,
        // и потерянный @import проявился бы только глазами на конкретной странице.
        expect(css).toContain('.anime-list__grid');
        expect(css).toContain('.anime-card__badge--watching');
        expect(css).toContain('.anime-detail__editable-error');
        expect(css).toContain('.plugin-widget__list');
    });

    test('produces a mirrored stylesheet for RTL locales', () => {
        expect(css).toContain('.text-start{text-align:left !important}');
        expect(rtlCss).toContain('.text-start{text-align:right !important}');
    });

    test('leaves logical properties alone when mirroring', () => {
        // Собственные стили уже написаны на логических свойствах, RTLCSS их не трогает —
        // иначе отражение применилось бы дважды и уехало в обратную сторону.
        expect(rtlCss).toContain('inset-inline-end');
    });
});
