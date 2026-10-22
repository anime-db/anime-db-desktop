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
 * Gate: the signature module that ships in the packaged app verifies the backup-key test vector
 * under the runtime the app runs on. Electron's node:crypto is BoringSSL, so Ed25519 support is
 * checked there rather than assumed from the Node of the runner. scripts/ is not packaged, so this
 * runs from the repository and loads the module from the package.
 *
 * Usage:   ELECTRON_RUN_AS_NODE=1 dist/win-unpacked/<exe> scripts/check-update-verifier.js [path/to/signature.js] [path/to/vector-dir]
 *          module defaults to dist/win-unpacked/resources/app/native/updater/signature.js, the keys are
 *          the update-keys.json next to it; vector-dir (backup-vector.txt + .sig) defaults to
 *          tests/fixtures/update-keys
 * Exit:    0 the vector verifies with the second (backup) key and not with the first;
 *          1 it does not, or the module/keys/vector cannot be loaded
 */

const fs = require('node:fs');
const path = require('node:path');

const rootDir = path.resolve(__dirname, '..');
const DEFAULT_MODULE = path.join(rootDir, 'dist', 'win-unpacked', 'resources', 'app', 'native', 'updater', 'signature.js');
const DEFAULT_VECTOR_DIR = path.join(rootDir, 'tests', 'fixtures', 'update-keys');

function main(argv = process.argv.slice(2)) {
    const modulePath = path.resolve(argv[0] ?? DEFAULT_MODULE);
    const vectorDir = path.resolve(argv[1] ?? DEFAULT_VECTOR_DIR);
    try {
        const signature = require(modulePath);
        const keys = signature.parseKeysFile(fs.readFileSync(path.join(path.dirname(modulePath), 'update-keys.json'), 'utf8'));
        if (keys.length < 2) {
            throw new Error('update-keys.json has no backup key');
        }
        const data = fs.readFileSync(path.join(vectorDir, 'backup-vector.txt'));
        const sig = fs.readFileSync(path.join(vectorDir, 'backup-vector.txt.sig'), 'utf8');
        if (!signature.verifySignature(data, sig, [keys[1]])) {
            throw new Error('the backup-key test vector does not verify with the backup key');
        }
        if (signature.verifySignature(data, sig, [keys[0]])) {
            throw new Error('the backup-key test vector verifies with the working key');
        }
    } catch (e) {
        console.error(`::error::update signature verifier check failed (${modulePath}): ${e.message}`);
        return 1;
    }
    console.log(`Update signature verifier OK on ${process.versions.electron ? `Electron ${process.versions.electron}` : `Node ${process.versions.node}`}.`);
    return 0;
}

if (require.main === module) {
    process.exitCode = main();
}

module.exports = { main };
