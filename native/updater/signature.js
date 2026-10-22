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
 * Detached Ed25519 signature check shared by the auto-updater client and the CI scripts.
 *
 * Depends on `node:crypto` only: the publishing job runs without `npm ci`, and the same file is
 * loaded from the packaged app by scripts/check-update-verifier.js under the Electron runtime.
 *
 * Trusted keys live in update-keys.json next to this module: a JSON array of base64 raw 32-byte
 * Ed25519 public keys. A signature is accepted when at least one of them verifies it. The signature
 * is base64 of 64 bytes (a trailing newline is allowed) over the exact bytes of the signed file.
 */

const crypto = require('node:crypto');

const KEY_LENGTH = 32;
const SIGNATURE_LENGTH = 64;
// DER prefix of SubjectPublicKeyInfo for Ed25519; the raw 32-byte key follows it.
const SPKI_PREFIX = Buffer.from('302a300506032b6570032100', 'hex');
const BASE64 = /^[A-Za-z0-9+/]+={0,2}$/;

function decodeBase64(value, what) {
    if (typeof value !== 'string' || value.length === 0 || value.length % 4 !== 0 || !BASE64.test(value)) {
        throw new Error(`${what} is not valid base64`);
    }
    return Buffer.from(value, 'base64');
}

/**
 * @param {unknown} list parsed content of update-keys.json
 * @returns {Buffer[]} raw 32-byte public keys
 */
function parseKeys(list) {
    if (!Array.isArray(list) || list.length === 0) {
        throw new Error('the key list must be a non-empty array');
    }
    return list.map((value, index) => {
        const raw = decodeBase64(value, `key #${index}`);
        if (raw.length !== KEY_LENGTH) {
            throw new Error(`key #${index} is ${raw.length} bytes, expected ${KEY_LENGTH}`);
        }
        return raw;
    });
}

/**
 * @param {string} text content of update-keys.json
 * @returns {Buffer[]}
 */
function parseKeysFile(text) {
    return parseKeys(JSON.parse(text));
}

function importPublicKey(raw) {
    return crypto.createPublicKey({ key: Buffer.concat([SPKI_PREFIX, raw]), format: 'der', type: 'spki' });
}

/**
 * @param {string|Buffer} text content of a .sig file
 * @returns {Buffer} 64 signature bytes
 */
function decodeSignature(text) {
    const signature = decodeBase64(String(text).trim(), 'signature');
    if (signature.length !== SIGNATURE_LENGTH) {
        throw new Error(`signature is ${signature.length} bytes, expected ${SIGNATURE_LENGTH}`);
    }
    return signature;
}

/**
 * @param {Buffer} data exact signed bytes
 * @param {string|Buffer} signatureText content of the .sig file
 * @param {Buffer[]} keys raw 32-byte public keys
 * @returns {number} index of the first key that verifies the signature, -1 if none does or the
 *                   signature is malformed
 */
function findSigningKey(data, signatureText, keys) {
    let signature;
    try {
        signature = decodeSignature(signatureText);
    } catch {
        return -1;
    }
    return keys.findIndex((raw) => crypto.verify(null, data, importPublicKey(raw), signature));
}

/**
 * @returns {boolean} true when at least one of the keys verifies the signature
 */
function verifySignature(data, signatureText, keys) {
    return findSigningKey(data, signatureText, keys) !== -1;
}

module.exports = { KEY_LENGTH, SIGNATURE_LENGTH, parseKeys, parseKeysFile, decodeSignature, findSigningKey, verifySignature };
