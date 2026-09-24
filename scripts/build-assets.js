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
const path = require('path');
const sass = require('sass');
const rtlcss = require('rtlcss');
const esbuild = require('esbuild');

const rootDir = path.resolve(__dirname, '..');
const nodeModulesDir = path.join(rootDir, 'node_modules');
const scssEntry = path.join(rootDir, 'app', 'assets', 'scss', 'app.scss');
const jsSourceDir = path.join(rootDir, 'app', 'assets', 'js');
const cssDir = path.join(rootDir, 'app', 'public', 'css');
const jsDir = path.join(rootDir, 'app', 'public', 'js');

/**
 * Third-party scripts served from app/public/js. Copied rather than referenced from
 * node_modules: node_modules is excluded from the packaged app (see package.json
 * "build.files"), and the Content-Security-Policy set in native/content-security-policy.js
 * is script-src 'self', so a CDN is not an option either.
 */
const VENDOR_SCRIPTS = [
    ['htmx.org', 'dist/htmx.min.js', 'htmx.min.js'],
    ['bootstrap', 'dist/js/bootstrap.bundle.min.js', 'bootstrap.bundle.min.js'],
];

/**
 * Copies the third-party scripts listed above into app/public/js.
 *
 * @param {string} outDir
 */
function copyVendorScripts(outDir = jsDir) {
    fs.mkdirSync(outDir, { recursive: true });

    for (const [pkg, source, target] of VENDOR_SCRIPTS) {
        fs.copyFileSync(path.join(nodeModulesDir, pkg, ...source.split('/')), path.join(outDir, target));
    }
}

/**
 * Compiles app/assets/scss/app.scss into app/public/css/app.css and its RTL counterpart.
 *
 * The RTL file is produced by running the compiled CSS through RTLCSS instead of a second
 * Sass build: Bootstrap has no Sass switch for RTL, its own dist ships bootstrap.rtl.css
 * generated exactly this way. Application styles are unaffected by the pass — they already
 * use logical properties (inset-inline-end and friends), which RTLCSS leaves alone.
 *
 * @param {string} entry
 * @param {string} outDir
 */
function compileStyles(entry = scssEntry, outDir = cssDir) {
    fs.mkdirSync(outDir, { recursive: true });

    const { css } = sass.compile(entry, {
        loadPaths: [nodeModulesDir],
        style: 'compressed',
        // Предупреждения из node_modules гасим целиком: устаревшие конструкции внутри
        // Bootstrap чинить не нам, а их сотни. Свои предупреждения при этом остаются видны.
        quietDeps: true,
        // Единственное, что приходится глушить точечно, — @import в app.scss. Bootstrap 5.3
        // не поддерживает @use (конфигурация переменными работает только через @import),
        // поэтому перейти на модульный синтаксис можно будет не раньше его 6-й версии.
        silenceDeprecations: ['import'],
    });

    fs.writeFileSync(path.join(outDir, 'app.css'), css, 'utf8');
    fs.writeFileSync(path.join(outDir, 'app.rtl.css'), rtlcss.process(css), 'utf8');
}

/**
 * Builds the esbuild entry that bundleScripts() below feeds to esbuild: an `import` per module in
 * sourceDir, controller.js first and everything else sorted after it. controller.js defines
 * window.Controller, which every other module's registerControl() call (issue #734) needs already
 * in place, and fs.readdirSync's own order is not guaranteed and differs between filesystems, so
 * the rest is sorted rather than left in whatever order the directory listing came back in.
 *
 * Throws if controller.js is missing (including an empty sourceDir) rather than producing a
 * bundle silently missing the control registry: a `main.js` that mounts nothing is a page that
 * looks fine until the first htmx swap, with no failing test or build error pointing at why.
 *
 * @param {string} sourceDir
 * @returns {string}
 */
function buildScriptsEntry(sourceDir) {
    const files = fs.readdirSync(sourceDir).filter((name) => name.endsWith('.js'));

    if (!files.includes('controller.js')) {
        throw new Error(`${sourceDir} has no controller.js — the control registry every other module's registerControl() call depends on.`);
    }

    const rest = files.filter((name) => name !== 'controller.js').sort();

    return ['controller.js', ...rest].map((name) => `import './${name}';`).join('\n') + '\n';
}

/**
 * Bundles every module in app/assets/js into the single app/public/js/main.js base.html.twig
 * loads (issue #735). Fed through esbuild's `stdin` option rather than a temporary entry file on
 * disk, so the generated import list never touches the source tree; `resolveDir` is what makes
 * the relative `./name.js` specifiers in that list resolve against sourceDir.
 *
 * Source maps carry `sourcesContent` because app/assets/** is excluded from the packaged app
 * (see "build.files" in package.json) — a map without embedded sources would be unable to show
 * the original file in an installed build.
 *
 * @param {string} sourceDir
 * @param {string} outDir
 */
function bundleScripts(sourceDir = jsSourceDir, outDir = jsDir) {
    fs.mkdirSync(outDir, { recursive: true });

    esbuild.buildSync({
        stdin: {
            contents:   buildScriptsEntry(sourceDir),
            resolveDir: sourceDir,
            sourcefile: 'main.entry.js',
            loader:     'js',
        },
        bundle:         true,
        platform:       'browser',
        format:         'iife',
        outfile:        path.join(outDir, 'main.js'),
        sourcemap:      true,
        sourcesContent: true,
    });
}

function main() {
    copyVendorScripts();
    compileStyles();
    bundleScripts();
}

if (require.main === module) {
    main();
}

module.exports = { copyVendorScripts, compileStyles, buildScriptsEntry, bundleScripts, main };
