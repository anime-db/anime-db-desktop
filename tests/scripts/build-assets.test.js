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

const { copyVendorScripts, compileStyles, buildScriptsEntry, bundleScripts } = require('../../scripts/build-assets');

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

describe('buildScriptsEntry', () => {
    // fs.readdirSync is mocked here rather than backed by a real directory: its order is not
    // guaranteed by Node and differs across filesystems, so a fixture built from real files could
    // pass this test by coincidence on a filesystem that already happens to hand back sorted
    // entries, even if buildScriptsEntry's own sort() were removed.
    afterEach(() => {
        jest.restoreAllMocks();
    });

    test('orders controller.js first, then the rest of the directory sorted', () => {
        jest.spyOn(fs, 'readdirSync').mockReturnValue(['zebra.js', 'controller.js', 'alpha.js', 'beta.js']);

        expect(buildScriptsEntry('/fake/dir')).toBe(
            "import './controller.js';\nimport './alpha.js';\nimport './beta.js';\nimport './zebra.js';\n",
        );
    });

    test('ignores non-.js files when building the entry', () => {
        jest.spyOn(fs, 'readdirSync').mockReturnValue(['controller.js', 'notes.txt']);

        expect(buildScriptsEntry('/fake/dir')).toBe("import './controller.js';\n");
    });

    test('throws instead of silently building without the control registry', () => {
        // Covers both ways controller.js can be missing (issue #735's open question): a directory
        // that has other modules but not controller.js, and one that has nothing at all.
        const emptyDir = tempDir('js-entry-empty-');
        expect(() => buildScriptsEntry(emptyDir)).toThrow(/controller\.js/);

        const sourceDir = tempDir('js-entry-no-controller-');
        fs.writeFileSync(path.join(sourceDir, 'a.js'), '', 'utf8');
        expect(() => buildScriptsEntry(sourceDir)).toThrow(/controller\.js/);
    });
});

describe('bundleScripts', () => {
    let bundle;
    let map;

    beforeAll(() => {
        const outDir = tempDir('scripts-');
        bundleScripts(undefined, outDir);
        bundle = fs.readFileSync(path.join(outDir, 'main.js'), 'utf8');
        map = JSON.parse(fs.readFileSync(path.join(outDir, 'main.js.map'), 'utf8'));
    }, COMPILE_TIMEOUT_MS);

    test('carries every application module, not just the ones a page used to load directly', () => {
        // Each marker below used to arrive through its own per-page <script> tag (issue #735) —
        // a module dropped while generating the bundle would only ever be caught by opening that
        // one page and reading the console, not by a page-agnostic test like this one.
        expect(bundle).toContain('window.Controller');
        expect(bundle).toContain('window.AnimeListQuery');
        expect(bundle).toContain('window.AnimeListGrid');
        expect(bundle).toContain('window.AnimeListFilterRender');
        expect(bundle).toContain('window.AnimeListFilterPanel');
        expect(bundle).toContain('window.AppTranslations');
        expect(bundle).toContain('window.ScanWatcher');
        expect(bundle).toContain('registerControl("anime-list"');
        expect(bundle).toContain('registerControl("catalog-back-link"');
        expect(bundle).toContain('registerControl("labels-widget"');
        expect(bundle).toContain('registerControl("open-folder-button"');
        expect(bundle).toContain('registerControl("app-notifications"');
        expect(bundle).toContain('registerControl("settings-backup"');
        expect(bundle).toContain('registerControl("storage-new"');
        expect(bundle).toContain('registerControl("storage-scan"');
        expect(bundle).toContain('registerControl("plugin-install"');
    });

    test('puts controller.js ahead of every other module, in a deterministic order', () => {
        const names = map.sources.map((source) => path.basename(source));

        expect(names[0]).toBe('controller.js');
        expect(names.slice(1)).toEqual([...names.slice(1)].sort());
    });

    test('embeds every source file, not just its path', () => {
        // app/assets/** is excluded from the packaged app (see "build.files" in package.json) —
        // without sourcesContent, a map shipped in an installed build could not show the original
        // file at all.
        expect(map.sourcesContent).toHaveLength(map.sources.length);
        map.sourcesContent.forEach((content) => expect(typeof content).toBe('string'));
        map.sourcesContent.forEach((content) => expect(content.length).toBeGreaterThan(0));
    });

    test('points the bundle at its source map', () => {
        expect(bundle).toContain('//# sourceMappingURL=main.js.map');
    });
});
