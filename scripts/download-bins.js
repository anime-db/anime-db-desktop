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

const https = require('https');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const crypto = require('crypto');

const versions = JSON.parse(fs.readFileSync(path.resolve(__dirname, 'versions.json'), 'utf8'));
const binDir = path.resolve(__dirname, '..', 'bin');

// Pinned Ed25519 public key for gpslab/qbittorrent-nox-win-build releases.
// Must match `signing-key.pub` published in that repository. Never fetched from the network.
const QBITTORRENT_NOX_PUBLIC_KEY = `-----BEGIN PUBLIC KEY-----
MCowBQYDK2VwAyEAY2beFPHj/tmY6qJY1rDOk4L12YIKdICTzDkW5sgf0xg=
-----END PUBLIC KEY-----
`;

const BINS = [
    {
        name: 'frankenphp',
        version: versions.frankenphp,
        url: `https://github.com/php/frankenphp/releases/download/v${versions.frankenphp}/frankenphp-windows-x86_64.zip`,
        dest: path.join(binDir, 'frankenphp', 'frankenphp.exe'),
        zipEntry: 'frankenphp.exe',
    },
    {
        name: 'meilisearch',
        version: versions.meilisearch,
        url: `https://github.com/meilisearch/meilisearch/releases/download/v${versions.meilisearch}/meilisearch-windows-amd64.exe`,
        dest: path.join(binDir, 'meilisearch', 'meilisearch.exe'),
        zipEntry: null,
    },
];

const QBITTORRENT_NOX_TAG = `qbt-nox-${versions.qbittorrentNox}`;
// Release asset filenames use ONLY the upstream qBittorrent version (e.g. "5.2.3");
// the release tag carries the full "<upstream>_<build>" (e.g. "5.2.3_2").
const [QBITTORRENT_NOX_UPSTREAM_VERSION] = versions.qbittorrentNox.split('_');
const QBITTORRENT_NOX_ZIP_NAME = `qbittorrent-nox-${QBITTORRENT_NOX_UPSTREAM_VERSION}-win-x64.zip`;
const QBITTORRENT_NOX_RELEASE_BASE = `https://github.com/gpslab/qbittorrent-nox-win-build/releases/download/${QBITTORRENT_NOX_TAG}`;

const QBITTORRENT_NOX = {
    name: 'qbittorrent-nox',
    version: versions.qbittorrentNox,
    zipUrl: `${QBITTORRENT_NOX_RELEASE_BASE}/${QBITTORRENT_NOX_ZIP_NAME}`,
    sigUrl: `${QBITTORRENT_NOX_RELEASE_BASE}/${QBITTORRENT_NOX_ZIP_NAME}.sig`,
    sumsUrl: `${QBITTORRENT_NOX_RELEASE_BASE}/SHA256SUMS`,
    zipName: QBITTORRENT_NOX_ZIP_NAME,
    destDir: path.join(binDir, 'qbittorrent-nox'),
    dest: path.join(binDir, 'qbittorrent-nox', 'qbittorrent-nox.exe'),
};

function versionFilePath(dest) {
    return path.join(path.dirname(dest), '.version');
}

function isUpToDate(dest, version) {
    if (!fs.existsSync(dest)) return false;
    const vf = versionFilePath(dest);
    if (!fs.existsSync(vf)) return false;
    return fs.readFileSync(vf, 'utf8').trim() === version;
}

function httpGetFollowingRedirects(url, onResponse, reject) {
    const follow = (currentUrl) => {
        const opts = { headers: { 'User-Agent': 'anime-db-desktop/download-bins' } };
        https.get(currentUrl, opts, (res) => {
            if (res.statusCode === 301 || res.statusCode === 302) {
                res.resume();
                follow(res.headers.location);
                return;
            }
            if (res.statusCode !== 200) {
                reject(new Error(`HTTP ${res.statusCode} for ${currentUrl}`));
                return;
            }
            onResponse(res);
        }).on('error', reject);
    };
    follow(url);
}

function download(url, destPath) {
    return new Promise((resolve, reject) => {
        httpGetFollowingRedirects(url, (res) => {
            const file = fs.createWriteStream(destPath);
            res.pipe(file);
            file.on('finish', () => file.close(resolve));
            file.on('error', (err) => {
                fs.unlink(destPath, () => {});
                reject(err);
            });
            res.on('error', (err) => {
                fs.unlink(destPath, () => {});
                reject(err);
            });
        }, reject);
    });
}

function downloadBuffer(url) {
    return new Promise((resolve, reject) => {
        httpGetFollowingRedirects(url, (res) => {
            const chunks = [];
            res.on('data', (chunk) => chunks.push(chunk));
            res.on('end', () => resolve(Buffer.concat(chunks)));
            res.on('error', reject);
        }, reject);
    });
}

// Parses the ZIP End of Central Directory + Central Directory records.
function readZipCentralDirectory(zipBuffer) {
    let eocdOffset = -1;
    const maxComment = Math.min(65535, zipBuffer.length - 22);
    for (let i = zipBuffer.length - 22; i >= zipBuffer.length - 22 - maxComment; i--) {
        if (zipBuffer.readUInt32LE(i) === 0x06054b50) {
            eocdOffset = i;
            break;
        }
    }
    if (eocdOffset === -1) throw new Error('Invalid ZIP: EOCD not found');

    const cdCount = zipBuffer.readUInt16LE(eocdOffset + 10);
    const cdOffset = zipBuffer.readUInt32LE(eocdOffset + 16);

    const entries = [];
    let offset = cdOffset;
    for (let i = 0; i < cdCount; i++) {
        if (zipBuffer.readUInt32LE(offset) !== 0x02014b50) {
            throw new Error(`Invalid ZIP: bad Central Directory signature at offset ${offset}`);
        }
        const method = zipBuffer.readUInt16LE(offset + 10);
        const compressedSize = zipBuffer.readUInt32LE(offset + 20);
        const fnLen = zipBuffer.readUInt16LE(offset + 28);
        const extraLen = zipBuffer.readUInt16LE(offset + 30);
        const commentLen = zipBuffer.readUInt16LE(offset + 32);
        const lhOffset = zipBuffer.readUInt32LE(offset + 42);
        const fileName = zipBuffer.slice(offset + 46, offset + 46 + fnLen).toString('utf8');

        entries.push({ fileName, method, compressedSize, lhOffset });

        offset += 46 + fnLen + extraLen + commentLen;
    }

    return entries;
}

function readZipEntryData(zipBuffer, entry) {
    if (zipBuffer.readUInt32LE(entry.lhOffset) !== 0x04034b50) {
        throw new Error('Invalid ZIP: bad Local File Header signature');
    }
    const lhFnLen = zipBuffer.readUInt16LE(entry.lhOffset + 26);
    const lhExtraLen = zipBuffer.readUInt16LE(entry.lhOffset + 28);
    const dataStart = entry.lhOffset + 30 + lhFnLen + lhExtraLen;
    const compressed = zipBuffer.slice(dataStart, dataStart + entry.compressedSize);

    if (entry.method === 0) return compressed;
    if (entry.method === 8) return zlib.inflateRawSync(compressed);
    throw new Error(`Unsupported ZIP compression method: ${entry.method}`);
}

function extractFromZip(zipBuffer, targetFilename, destPath) {
    const entries = readZipCentralDirectory(zipBuffer);

    for (const entry of entries) {
        if (path.basename(entry.fileName.replace(/\\/g, '/')) === targetFilename) {
            const data = readZipEntryData(zipBuffer, entry);
            fs.mkdirSync(path.dirname(destPath), { recursive: true });
            fs.writeFileSync(destPath, data);
            return;
        }
    }

    throw new Error(`${targetFilename} not found in ZIP`);
}

function extractZipToDir(zipBuffer, destDir) {
    const entries = readZipCentralDirectory(zipBuffer);

    for (const entry of entries) {
        const normalizedName = entry.fileName.replace(/\\/g, '/');
        if (normalizedName.endsWith('/')) continue; // directory entry

        const data = readZipEntryData(zipBuffer, entry);
        const destPath = path.join(destDir, normalizedName);
        // Defense-in-depth against zip-slip (bundle is signature-verified, but never trust entry paths).
        if (!path.resolve(destPath).startsWith(path.resolve(destDir) + path.sep)) {
            throw new Error(`Refusing to extract ZIP entry outside destination: ${entry.fileName}`);
        }
        fs.mkdirSync(path.dirname(destPath), { recursive: true });
        fs.writeFileSync(destPath, data);
    }
}

// Parses a `SHA256SUMS` file (`<hex>  <filename>` per line, optional `*` before filename) and
// returns the lowercase hex digest for `filename`, or null when not listed.
function parseSha256Sums(text, filename) {
    for (const line of text.split('\n')) {
        const trimmed = line.trim();
        if (!trimmed) continue;
        const match = trimmed.match(/^([0-9a-fA-F]{64})\s+\*?(.+)$/);
        if (match && path.basename(match[2].trim()) === filename) {
            return match[1].toLowerCase();
        }
    }
    return null;
}

// Verifies a raw Ed25519 signature (base64) over `buffer` against a PEM-encoded public key.
function verifyEd25519Signature(buffer, signatureBase64, publicKeyPem) {
    const signature = Buffer.from(signatureBase64.trim(), 'base64');
    return crypto.verify(null, buffer, publicKeyPem, signature);
}

async function downloadBin(bin) {
    if (isUpToDate(bin.dest, bin.version)) {
        console.log(`${bin.name} v${bin.version} already up to date, skipping`);
        return;
    }

    fs.mkdirSync(path.dirname(bin.dest), { recursive: true });

    if (bin.zipEntry) {
        const tmpZip = bin.dest + '.tmp.zip';
        console.log(`Downloading ${bin.name} v${bin.version}...`);
        await download(bin.url, tmpZip);
        console.log(`Extracting ${bin.zipEntry} from archive...`);
        const zipBuffer = fs.readFileSync(tmpZip);
        extractFromZip(zipBuffer, bin.zipEntry, bin.dest);
        fs.unlinkSync(tmpZip);
    } else {
        const tmpFile = bin.dest + '.tmp';
        console.log(`Downloading ${bin.name} v${bin.version}...`);
        await download(bin.url, tmpFile);
        fs.renameSync(tmpFile, bin.dest);
    }

    fs.writeFileSync(versionFilePath(bin.dest), bin.version + '\n');
    console.log(`${bin.name} v${bin.version} downloaded successfully`);
}

// Downloads the prebuilt qbittorrent-nox bundle and verifies its SHA-256 checksum and Ed25519
// signature BEFORE extracting anything. Throws (and extracts nothing) on any verification failure.
async function downloadQbittorrentNox(bin) {
    if (isUpToDate(bin.dest, bin.version)) {
        console.log(`${bin.name} v${bin.version} already up to date, skipping`);
        return;
    }

    console.log(`Downloading ${bin.name} v${bin.version}...`);
    const [zipBuffer, sumsText, sigBase64] = await Promise.all([
        downloadBuffer(bin.zipUrl),
        downloadBuffer(bin.sumsUrl).then((b) => b.toString('utf8')),
        downloadBuffer(bin.sigUrl).then((b) => b.toString('utf8')),
    ]);

    console.log(`Verifying ${bin.name} SHA-256 checksum...`);
    const expectedHash = parseSha256Sums(sumsText, bin.zipName);
    if (!expectedHash) {
        throw new Error(`SHA-256 for ${bin.zipName} not found in SHA256SUMS`);
    }
    const actualHash = crypto.createHash('sha256').update(zipBuffer).digest('hex');
    if (actualHash !== expectedHash) {
        throw new Error(`SHA-256 mismatch for ${bin.zipName}: expected ${expectedHash}, got ${actualHash}`);
    }

    console.log(`Verifying ${bin.name} Ed25519 signature...`);
    if (!verifyEd25519Signature(zipBuffer, sigBase64, QBITTORRENT_NOX_PUBLIC_KEY)) {
        throw new Error(`Ed25519 signature verification failed for ${bin.zipName}`);
    }

    console.log(`Extracting ${bin.name} archive...`);
    fs.mkdirSync(bin.destDir, { recursive: true });
    extractZipToDir(zipBuffer, bin.destDir);

    fs.writeFileSync(versionFilePath(bin.dest), bin.version + '\n');
    console.log(`${bin.name} v${bin.version} downloaded successfully`);
}

async function main() {
    for (const bin of BINS) {
        await downloadBin(bin);
    }
    await downloadQbittorrentNox(QBITTORRENT_NOX);
}

if (require.main === module) {
    main().catch((err) => {
        console.error('download-bins failed:', err.message);
        process.exit(1);
    });
}

module.exports = {
    parseSha256Sums,
    verifyEd25519Signature,
    extractZipToDir,
    extractFromZip,
    downloadQbittorrentNox,
    QBITTORRENT_NOX,
    QBITTORRENT_NOX_PUBLIC_KEY,
};
