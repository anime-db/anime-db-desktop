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

const rootDir = path.resolve(__dirname, '..');
const nodeModulesDir = path.join(rootDir, 'node_modules');
const scssEntry = path.join(rootDir, 'app', 'assets', 'scss', 'app.scss');
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

function main() {
    copyVendorScripts();
    compileStyles();
}

if (require.main === module) {
    main();
}

module.exports = { copyVendorScripts, compileStyles, main };
