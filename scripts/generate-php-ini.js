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
const phpIni = require('../native/php-ini');

const rootDir = path.resolve(__dirname, '..');

// Same layout scripts/download-bins.js extracts frankenphp.exe's DLLs into — this script is meant
// to be run after that one in CI, against the same checkout.
const DEFAULT_EXTENSION_DIR = path.join(rootDir, 'bin', 'frankenphp', 'ext');

/**
 * Renders a minimal `--flag value` argv into an options object. Unknown flags are ignored so this
 * stays a thin CLI wrapper, not a general-purpose parser.
 *
 * @param {string[]} argv
 * @returns {{ extensionDir?: string, targetDir?: string, timezone?: string }}
 */
function parseArgs(argv) {
    const options = {};
    for (let i = 0; i < argv.length; i++) {
        switch (argv[i]) {
            case '--extension-dir':
                options.extensionDir = argv[++i];
                break;
            case '--target-dir':
                options.targetDir = argv[++i];
                break;
            case '--timezone':
                options.timezone = argv[++i];
                break;
        }
    }
    return options;
}

/**
 * Generates a php.ini configured the same way the desktop supervisor configures it (see
 * ensurePhpIni() in native/supervisor/frankenphp.js), without requiring Electron. Defaults to a
 * fresh temp directory so the result can be pointed at from PHPRC without touching any existing
 * installation.
 *
 * @param {{ extensionDir?: string, targetDir?: string, timezone?: string }} [options]
 * @returns {string} the directory containing the generated php.ini — this is the value PHPRC
 *                    expects (a directory, not the file path itself)
 */
function generate({ extensionDir = DEFAULT_EXTENSION_DIR, targetDir, timezone } = {}) {
    const iniDir = targetDir ?? fs.mkdtempSync(path.join(os.tmpdir(), 'anime-db-php-ini-'));
    const iniPath = path.join(iniDir, 'php.ini');

    phpIni.ensurePhpIni({ iniPath, iniDir, extensionDir, timezone });

    return iniDir;
}

function main() {
    const iniDir = generate(parseArgs(process.argv.slice(2)));
    console.log(iniDir);
}

if (require.main === module) {
    main();
}

module.exports = { generate, parseArgs };
