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

/**
 * Verifies the detached signature of latest.yml against the trusted key file (not against a key
 * derived from the signing secret): a wrong public key in the client fails here, in CI.
 *
 * Usage:   node scripts/verify-update-signature.js <path/to/latest.yml> [path/to/latest.yml.sig] [path/to/update-keys.json]
 *          .sig defaults to `<latest.yml>.sig`, keys to native/updater/update-keys.json
 * Exit:    0 verified by at least one key from the file; 1 signature rejected (unknown key, changed
 *          byte, malformed), or a file is missing/invalid; 2 wrong usage
 *
 * Needs Node 22 only, no node_modules.
 */

const fs = require('node:fs');
const path = require('node:path');
const { parseKeysFile, findSigningKey } = require('../native/updater/signature');

const DEFAULT_KEYS = path.resolve(__dirname, '..', 'native', 'updater', 'update-keys.json');

function main(argv = process.argv.slice(2)) {
    if (argv.length < 1 || argv.length > 3) {
        console.error('Usage: verify-update-signature.js <latest.yml> [latest.yml.sig] [update-keys.json]');
        return 2;
    }
    const [file, sigFile = `${argv[0]}.sig`, keysFile = DEFAULT_KEYS] = argv;
    try {
        const index = findSigningKey(fs.readFileSync(file), fs.readFileSync(sigFile, 'utf8'), parseKeysFile(fs.readFileSync(keysFile, 'utf8')));
        if (index === -1) {
            console.error(`::error::${sigFile} is not a valid signature of ${file} by any key from ${keysFile}`);
            return 1;
        }
        console.log(`Signature verified by key #${index} of ${keysFile}.`);
        return 0;
    } catch (e) {
        console.error(`::error::signature check failed: ${e.message}`);
        return 1;
    }
}

if (require.main === module) {
    process.exitCode = main();
}

module.exports = { main };
