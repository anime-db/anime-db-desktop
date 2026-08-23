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

const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { EventEmitter } = require('events');

jest.mock('https', () => ({ get: jest.fn() }));
const https = require('https');

const {
    parseSha256Sums,
    verifyEd25519Signature,
    extractZipToDir,
    extractFromZip,
    downloadBin,
    downloadQbittorrentNox,
    QBITTORRENT_NOX,
    QBITTORRENT_NOX_PUBLIC_KEY,
} = require('../../scripts/download-bins');

// Builds a minimal, uncompressed (stored) ZIP archive in memory. CRC-32 is written as 0 since
// download-bins.js does not validate it (integrity is verified via SHA-256 + Ed25519 upstream).
function buildZip(entries) {
    const localParts = [];
    const centralParts = [];
    let offset = 0;

    for (const { name, data } of entries) {
        const nameBuf = Buffer.from(name, 'utf8');

        const localHeader = Buffer.alloc(30);
        localHeader.writeUInt32LE(0x04034b50, 0);
        localHeader.writeUInt16LE(20, 4);
        localHeader.writeUInt16LE(0, 6);
        localHeader.writeUInt16LE(0, 8);
        localHeader.writeUInt16LE(0, 10);
        localHeader.writeUInt16LE(0, 12);
        localHeader.writeUInt32LE(0, 14);
        localHeader.writeUInt32LE(data.length, 18);
        localHeader.writeUInt32LE(data.length, 22);
        localHeader.writeUInt16LE(nameBuf.length, 26);
        localHeader.writeUInt16LE(0, 28);

        localParts.push(localHeader, nameBuf, data);

        const centralHeader = Buffer.alloc(46);
        centralHeader.writeUInt32LE(0x02014b50, 0);
        centralHeader.writeUInt16LE(20, 4);
        centralHeader.writeUInt16LE(20, 6);
        centralHeader.writeUInt16LE(0, 8);
        centralHeader.writeUInt16LE(0, 10);
        centralHeader.writeUInt16LE(0, 12);
        centralHeader.writeUInt16LE(0, 14);
        centralHeader.writeUInt32LE(0, 16);
        centralHeader.writeUInt32LE(data.length, 20);
        centralHeader.writeUInt32LE(data.length, 24);
        centralHeader.writeUInt16LE(nameBuf.length, 28);
        centralHeader.writeUInt16LE(0, 30);
        centralHeader.writeUInt16LE(0, 32);
        centralHeader.writeUInt16LE(0, 34);
        centralHeader.writeUInt16LE(0, 36);
        centralHeader.writeUInt32LE(0, 38);
        centralHeader.writeUInt32LE(offset, 42);

        centralParts.push(centralHeader, nameBuf);

        offset += localHeader.length + nameBuf.length + data.length;
    }

    const centralDirOffset = offset;
    const centralDir = Buffer.concat(centralParts);

    const eocd = Buffer.alloc(22);
    eocd.writeUInt32LE(0x06054b50, 0);
    eocd.writeUInt16LE(0, 4);
    eocd.writeUInt16LE(0, 6);
    eocd.writeUInt16LE(entries.length, 8);
    eocd.writeUInt16LE(entries.length, 10);
    eocd.writeUInt32LE(centralDir.length, 12);
    eocd.writeUInt32LE(centralDirOffset, 16);
    eocd.writeUInt16LE(0, 20);

    return Buffer.concat([...localParts, centralDir, eocd]);
}

describe('parseSha256Sums', () => {
    const sums = [
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  qbittorrent-nox-5.2.3_1-win-x64.zip',
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb *SHA256SUMS.sig',
    ].join('\n');

    test('finds the hash for a listed file', () => {
        expect(parseSha256Sums(sums, 'qbittorrent-nox-5.2.3_1-win-x64.zip'))
            .toBe('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    });

    test('handles the "*" binary-mode marker before the filename', () => {
        expect(parseSha256Sums(sums, 'SHA256SUMS.sig'))
            .toBe('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    });

    test('returns null when the file is not listed', () => {
        expect(parseSha256Sums(sums, 'unknown.zip')).toBeNull();
    });
});

describe('verifyEd25519Signature', () => {
    const { publicKey, privateKey } = crypto.generateKeyPairSync('ed25519');
    const publicKeyPem = publicKey.export({ type: 'spki', format: 'pem' });
    const data = Buffer.from('qbittorrent-nox release bundle bytes');

    function sign(buffer) {
        return crypto.sign(null, buffer, privateKey).toString('base64');
    }

    test('accepts a valid signature from the matching key', () => {
        expect(verifyEd25519Signature(data, sign(data), publicKeyPem)).toBe(true);
    });

    test('rejects a signature computed over a tampered zip (SHA-256 mismatch scenario)', () => {
        const validSig = sign(data);
        const tampered = Buffer.from('qbittorrent-nox RELEASE bundle bytes');
        expect(verifyEd25519Signature(tampered, validSig, publicKeyPem)).toBe(false);
    });

    test('rejects a corrupted/garbage signature', () => {
        const garbageSig = Buffer.alloc(64, 0).toString('base64');
        expect(verifyEd25519Signature(data, garbageSig, publicKeyPem)).toBe(false);
    });

    test('rejects a valid signature checked against an unrelated (non-pinned) key', () => {
        const otherKeyPair = crypto.generateKeyPairSync('ed25519');
        const otherPublicKeyPem = otherKeyPair.publicKey.export({ type: 'spki', format: 'pem' });
        expect(verifyEd25519Signature(data, sign(data), otherPublicKeyPem)).toBe(false);
    });

    test('the pinned public key is a well-formed Ed25519 SPKI PEM', () => {
        const keyObject = crypto.createPublicKey(QBITTORRENT_NOX_PUBLIC_KEY);
        expect(keyObject.asymmetricKeyType).toBe('ed25519');
    });
});

describe('extractZipToDir / extractFromZip', () => {
    let tmpDir;

    beforeEach(() => {
        tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'download-bins-test-'));
    });

    afterEach(() => {
        fs.rmSync(tmpDir, { recursive: true, force: true });
    });

    test('extracts every file of a multi-entry archive, preserving relative paths', () => {
        const zip = buildZip([
            { name: 'qbittorrent-nox.exe', data: Buffer.from('binary-content') },
            { name: 'THIRD-PARTY-LICENSES/Qt6.txt', data: Buffer.from('license-text') },
            { name: 'versions.txt', data: Buffer.from('5.2.3_1') },
        ]);

        extractZipToDir(zip, tmpDir);

        expect(fs.readFileSync(path.join(tmpDir, 'qbittorrent-nox.exe'), 'utf8')).toBe('binary-content');
        expect(fs.readFileSync(path.join(tmpDir, 'THIRD-PARTY-LICENSES/Qt6.txt'), 'utf8')).toBe('license-text');
        expect(fs.readFileSync(path.join(tmpDir, 'versions.txt'), 'utf8')).toBe('5.2.3_1');
    });

    test('extractFromZip pulls a single named file out of the archive', () => {
        const zip = buildZip([
            { name: 'nested/frankenphp.exe', data: Buffer.from('php-binary') },
        ]);
        const dest = path.join(tmpDir, 'frankenphp.exe');

        extractFromZip(zip, 'frankenphp.exe', dest);

        expect(fs.readFileSync(dest, 'utf8')).toBe('php-binary');
    });

    test('extractZipToDir rejects path-traversal (zip-slip) entries', () => {
        const zip = buildZip([{ name: '../evil.exe', data: Buffer.from('x') }]);

        expect(() => extractZipToDir(zip, tmpDir)).toThrow(/outside destination/);
        expect(fs.existsSync(path.join(tmpDir, '..', 'evil.exe'))).toBe(false);
    });
});

// Mocks `https.get` so `downloadQbittorrentNox` never touches the network, letting these tests
// exercise the verify-before-extract orchestration itself: order of checks, and that a failed
// check aborts before anything is written to disk.
describe('downloadQbittorrentNox (verify-before-extract orchestration)', () => {
    let tmpDir;
    let bin;

    function mockDownloads({ zip, sums, sig }) {
        https.get.mockImplementation((url, opts, callback) => {
            const buffer = { [bin.zipUrl]: zip, [bin.sumsUrl]: sums, [bin.sigUrl]: sig }[url];
            if (!buffer) throw new Error(`Unexpected URL requested in test: ${url}`);

            const res = new EventEmitter();
            res.statusCode = 200;
            callback(res);
            res.emit('data', buffer);
            res.emit('end');

            return new EventEmitter();
        });
    }

    beforeEach(() => {
        tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'download-bins-orchestration-'));
        bin = {
            ...QBITTORRENT_NOX,
            destDir: path.join(tmpDir, 'out'),
            dest: path.join(tmpDir, 'out', 'qbittorrent-nox.exe'),
        };
    });

    afterEach(() => {
        fs.rmSync(tmpDir, { recursive: true, force: true });
        https.get.mockReset();
        jest.restoreAllMocks();
    });

    test('aborts before extracting anything when the SHA-256 checksum does not match', async () => {
        const zip = buildZip([{ name: 'qbittorrent-nox.exe', data: Buffer.from('tampered-bundle') }]);
        const sums = Buffer.from(
            `${'f'.repeat(64)}  ${bin.zipName}\n`,
        );
        mockDownloads({ zip, sums, sig: Buffer.from('irrelevant-signature') });

        await expect(downloadQbittorrentNox(bin)).rejects.toThrow(/SHA-256 mismatch/);
        expect(fs.existsSync(bin.destDir)).toBe(false);
    });

    test('aborts before extracting anything when the Ed25519 signature is invalid', async () => {
        const zip = buildZip([{ name: 'qbittorrent-nox.exe', data: Buffer.from('real-bundle') }]);
        const hash = crypto.createHash('sha256').update(zip).digest('hex');
        const sums = Buffer.from(`${hash}  ${bin.zipName}\n`);
        mockDownloads({ zip, sums, sig: Buffer.from('some-signature') });
        jest.spyOn(crypto, 'verify').mockReturnValueOnce(false);

        await expect(downloadQbittorrentNox(bin)).rejects.toThrow(/signature verification failed/);
        expect(fs.existsSync(bin.destDir)).toBe(false);
    });

    test('extracts the bundle and writes the version file once both checks pass', async () => {
        const zip = buildZip([{ name: 'qbittorrent-nox.exe', data: Buffer.from('real-bundle') }]);
        const hash = crypto.createHash('sha256').update(zip).digest('hex');
        const sums = Buffer.from(`${hash}  ${bin.zipName}\n`);
        mockDownloads({ zip, sums, sig: Buffer.from('a-matching-signature') });
        jest.spyOn(crypto, 'verify').mockReturnValueOnce(true);

        await downloadQbittorrentNox(bin);

        expect(fs.readFileSync(bin.dest, 'utf8')).toBe('real-bundle');
        expect(fs.readFileSync(path.join(bin.destDir, '.version'), 'utf8')).toBe(`${bin.version}\n`);
    });
});

// Mocks `https.get` so `downloadBin` never touches the network. Covers the same
// verify-before-write ordering as downloadQbittorrentNox above, plus the pieces specific to
// downloadBin: the 307/308 redirect codes and the timeout/retry wrapper around the download.
describe('downloadBin (verify-before-write, redirects, retries)', () => {
    let tmpDir;
    let bin;

    function mockSingleResponse(buffer) {
        https.get.mockImplementation((url, opts, callback) => {
            const res = new EventEmitter();
            res.statusCode = 200;
            res.resume = () => {};
            callback(res);
            res.emit('data', buffer);
            res.emit('end');
            return new EventEmitter();
        });
    }

    beforeEach(() => {
        tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'download-bins-bin-'));
        bin = {
            name: 'testbin',
            version: '1.0.0',
            url: 'https://example.invalid/testbin.exe',
            dest: path.join(tmpDir, 'testbin.exe'),
            zipEntry: null,
            sha256: null,
        };
    });

    afterEach(() => {
        fs.rmSync(tmpDir, { recursive: true, force: true });
        https.get.mockReset();
        jest.restoreAllMocks();
    });

    test('writes the file once the downloaded content matches the pinned SHA-256', async () => {
        const data = Buffer.from('trusted-binary-content');
        bin.sha256 = crypto.createHash('sha256').update(data).digest('hex');
        mockSingleResponse(data);

        await downloadBin(bin);

        expect(fs.readFileSync(bin.dest, 'utf8')).toBe('trusted-binary-content');
        expect(fs.readFileSync(path.join(tmpDir, '.version'), 'utf8')).toBe('1.0.0\n');
    });

    test('rejects and writes nothing when the downloaded content does not match the pinned SHA-256', async () => {
        const data = Buffer.from('tampered-binary-content');
        bin.sha256 = 'f'.repeat(64);
        mockSingleResponse(data);

        await expect(downloadBin(bin)).rejects.toThrow(/SHA-256 mismatch/);

        expect(fs.existsSync(bin.dest)).toBe(false);
        expect(fs.existsSync(bin.dest + '.tmp')).toBe(false);
        expect(fs.existsSync(path.join(tmpDir, '.version'))).toBe(false);
    });

    test('follows a 308 permanent redirect to the final URL', async () => {
        const data = Buffer.from('redirected-binary-content');
        bin.sha256 = crypto.createHash('sha256').update(data).digest('hex');
        const finalUrl = 'https://example.invalid/final/testbin.exe';

        https.get.mockImplementation((url, opts, callback) => {
            const res = new EventEmitter();
            res.resume = () => {};
            if (url === bin.url) {
                res.statusCode = 308;
                res.headers = { location: finalUrl };
                callback(res);
            } else if (url === finalUrl) {
                res.statusCode = 200;
                callback(res);
                res.emit('data', data);
                res.emit('end');
            } else {
                throw new Error(`Unexpected URL requested in test: ${url}`);
            }
            return new EventEmitter();
        });

        await downloadBin(bin);

        expect(fs.readFileSync(bin.dest, 'utf8')).toBe('redirected-binary-content');
    });

    test('retries the download and fails once all attempts are exhausted', async () => {
        let callCount = 0;
        https.get.mockImplementation(() => {
            callCount += 1;
            const req = new EventEmitter();
            setImmediate(() => req.emit('error', new Error('ECONNRESET')));
            return req;
        });

        await expect(downloadBin(bin)).rejects.toThrow(/ECONNRESET/);

        expect(callCount).toBe(4);
        expect(fs.existsSync(bin.dest)).toBe(false);
    }, 10000);
});
