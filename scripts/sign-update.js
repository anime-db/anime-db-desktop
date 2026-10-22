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
 * Signs latest.yml with an Ed25519 key (detached signature over the exact file bytes).
 *
 * Usage:   node scripts/sign-update.js <path/to/latest.yml> [path/to/output.sig]
 * Env:     UPDATE_SIGNING_KEY  private key, PKCS#8 PEM (`openssl genpkey -algorithm ed25519`)
 * Output:  the .sig file (default `<latest.yml>.sig`): base64 of 64 bytes + "\n"
 * Exit:    0 signed; 1 key missing/invalid/not Ed25519, or the input file is unreadable;
 *          2 wrong usage
 *
 * Needs Node 22 only, no node_modules.
 */

const crypto = require('node:crypto');
const fs = require('node:fs');

function sign(file, output, pem) {
    const key = crypto.createPrivateKey(pem);
    if (key.asymmetricKeyType !== 'ed25519') {
        throw new Error(`UPDATE_SIGNING_KEY is ${key.asymmetricKeyType}, expected ed25519`);
    }
    const signature = crypto.sign(null, fs.readFileSync(file), key);
    fs.writeFileSync(output, `${signature.toString('base64')}\n`);
}

function main(argv = process.argv.slice(2), env = process.env) {
    if (argv.length < 1 || argv.length > 2) {
        console.error('Usage: sign-update.js <path/to/latest.yml> [path/to/output.sig]');
        return 2;
    }
    if (!env.UPDATE_SIGNING_KEY) {
        console.error('::error::UPDATE_SIGNING_KEY is not set');
        return 1;
    }
    try {
        sign(argv[0], argv[1] ?? `${argv[0]}.sig`, env.UPDATE_SIGNING_KEY);
    } catch (e) {
        // The message never contains the key itself.
        console.error(`::error::signing failed: ${e.message}`);
        return 1;
    }
    return 0;
}

if (require.main === module) {
    process.exitCode = main();
}

module.exports = { sign, main };
