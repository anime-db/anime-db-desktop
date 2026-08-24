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

// `frankenphp.exe` is not self-contained: it dynamically links php8ts.dll (the PHP runtime itself)
// plus four more libraries, and the PHP extensions the app actually needs at runtime — not just
// what `composer check-platform-reqs --no-dev` in app/ declares, see the note below — load as
// separate DLLs under ext/. Both sets were confirmed empirically against the v1.12.4 release
// asset: `objdump -p frankenphp.exe | grep 'DLL Name'` for the runtime imports (including the
// brotlicommon.dll transitive dependency of brotlienc.dll/brotlidec.dll, which is easy to miss by
// inspection alone), and `objdump -p` on each ext/php_*.dll for its own dependency chain
// (intl → ICU, pdo_sqlite → libsqlite3.dll, openssl → libssl-3-x64.dll → libcrypto-3-x64.dll,
// mbstring → only php8ts.dll and OS-provided DLLs, same as zip, gd → only php8ts.dll and
// OS-provided DLLs, same as mbstring — its image codecs are linked into the DLL itself, not
// shipped as separate libwebp/libpng/libjpeg DLLs). ctype/iconv/json/xml have no ext/php_*.dll in
// the archive — this PHP build compiles them in statically, so they need no `extension=` line.
// See .claude-docs/decisions.md for the curated-set-vs-full-archive tradeoff and why the
// extension list isn't just check-platform-reqs output.
const FRANKENPHP_FILES = [
    'frankenphp.exe',
    'php8ts.dll',
    'brotlienc.dll',
    'brotlidec.dll',
    'brotlicommon.dll',
    'libwatcher-c.dll',
    'pthreadVC3.dll',
    'icudt77.dll',
    'icuin77.dll',
    'icuio77.dll',
    'icuuc77.dll',
    'libsqlite3.dll',
    'libssl-3-x64.dll',
    'libcrypto-3-x64.dll',
    'ext/php_intl.dll',
    'ext/php_zip.dll',
    'ext/php_pdo_sqlite.dll',
    'ext/php_openssl.dll',
    'ext/php_mbstring.dll',
    'ext/php_gd.dll',
];

const BINS = [
    {
        name: 'frankenphp',
        version: versions.frankenphp,
        url: `https://github.com/php/frankenphp/releases/download/v${versions.frankenphp}/frankenphp-windows-x86_64.zip`,
        dest: path.join(binDir, 'frankenphp', 'frankenphp.exe'),
        destDir: path.join(binDir, 'frankenphp'),
        zipEntries: FRANKENPHP_FILES,
        sha256: versions.sha256.frankenphp,
    },
    {
        name: 'meilisearch',
        version: versions.meilisearch,
        url: `https://github.com/meilisearch/meilisearch/releases/download/v${versions.meilisearch}/meilisearch-windows-amd64.exe`,
        dest: path.join(binDir, 'meilisearch', 'meilisearch.exe'),
        zipEntry: null,
        sha256: versions.sha256.meilisearch,
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

// A bin counts as up to date only when its version marker matches AND, for archives extracted via
// `zipEntries`, every one of those files is still present in `destDir`. Checking the version marker
// alone would treat a directory left over from an older script version (e.g. one that only extracted
// `frankenphp.exe`) as current, silently skipping the download that would have completed the set.
function isUpToDate(bin) {
    if (!fs.existsSync(bin.dest)) return false;
    const vf = versionFilePath(bin.dest);
    if (!fs.existsSync(vf)) return false;
    if (fs.readFileSync(vf, 'utf8').trim() !== bin.version) return false;
    if (bin.zipEntries) {
        return bin.zipEntries.every((entryPath) => fs.existsSync(path.join(bin.destDir, entryPath)));
    }
    return true;
}

const REDIRECT_STATUS_CODES = [301, 302, 307, 308];
const CONNECT_TIMEOUT_MS = 30000;
const DOWNLOAD_RETRIES = 3;
const RETRY_BASE_DELAY_MS = 500;

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function httpGetFollowingRedirects(url, onResponse, reject) {
    const follow = (currentUrl) => {
        const opts = { headers: { 'User-Agent': 'anime-db-desktop/download-bins' }, timeout: CONNECT_TIMEOUT_MS };
        const req = https.get(currentUrl, opts, (res) => {
            if (REDIRECT_STATUS_CODES.includes(res.statusCode)) {
                res.resume();
                follow(res.headers.location);
                return;
            }
            if (res.statusCode !== 200) {
                reject(new Error(`HTTP ${res.statusCode} for ${currentUrl}`));
                return;
            }
            onResponse(res);
        });
        req.on('timeout', () => req.destroy(new Error(`Connection timed out after ${CONNECT_TIMEOUT_MS}ms for ${currentUrl}`)));
        req.on('error', reject);
    };
    follow(url);
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

// Retries a whole-buffer download with a growing pause between attempts. Each attempt is an
// independent connection (fresh redirect chain), so a transient error or a truncated transfer
// never leaves a partial buffer behind — the caller either gets the full, complete download or
// the last error after all attempts are spent.
async function downloadBufferWithRetry(url) {
    let lastErr;
    for (let attempt = 0; attempt <= DOWNLOAD_RETRIES; attempt++) {
        try {
            return await downloadBuffer(url);
        } catch (err) {
            lastErr = err;
            if (attempt === DOWNLOAD_RETRIES) break;
            const delay = RETRY_BASE_DELAY_MS * 2 ** attempt;
            console.error(
                `Download failed (attempt ${attempt + 1}/${DOWNLOAD_RETRIES + 1}) for ${url}: ` +
                `${err.message}. Retrying in ${delay}ms...`,
            );
            await sleep(delay);
        }
    }
    throw lastErr;
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

// Extracts exactly the ZIP entries listed in `wantedPaths` (archive-relative, forward-slash paths)
// into `destDir`, preserving their relative subdirectories (e.g. `ext/php_intl.dll`). Throws if any
// requested entry is missing from the archive — a silent partial extraction here would ship a
// runtime that fails to start or load an extension, so it must fail the build instead.
function extractSelectedFromZip(zipBuffer, wantedPaths, destDir) {
    const entries = readZipCentralDirectory(zipBuffer);
    const wanted = new Set(wantedPaths);
    const matched = entries.filter((entry) => wanted.has(entry.fileName.replace(/\\/g, '/')));

    const foundNames = new Set(matched.map((entry) => entry.fileName.replace(/\\/g, '/')));
    const missing = wantedPaths.filter((p) => !foundNames.has(p));
    if (missing.length > 0) {
        throw new Error(`Entries not found in ZIP: ${missing.join(', ')}`);
    }

    for (const entry of matched) {
        const normalizedName = entry.fileName.replace(/\\/g, '/');
        const data = readZipEntryData(zipBuffer, entry);
        const destPath = path.join(destDir, normalizedName);
        // Defense-in-depth against zip-slip (bundle is checksum-verified, but never trust entry paths).
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

// Downloads a bin into memory and verifies its SHA-256 checksum BEFORE writing anything to disk.
// Throws (and writes nothing — no target file, no temp file) on a checksum mismatch.
async function downloadBin(bin) {
    if (isUpToDate(bin)) {
        console.log(`${bin.name} v${bin.version} already up to date, skipping`);
        return;
    }

    if (!bin.sha256) {
        throw new Error(
            `Missing pinned SHA-256 for ${bin.name} — add it to versions.sha256.${bin.name} in scripts/versions.json`,
        );
    }

    console.log(`Downloading ${bin.name} v${bin.version}...`);
    const buffer = await downloadBufferWithRetry(bin.url);

    console.log(`Verifying ${bin.name} SHA-256 checksum...`);
    const actualHash = crypto.createHash('sha256').update(buffer).digest('hex');
    if (actualHash !== bin.sha256) {
        throw new Error(`SHA-256 mismatch for ${bin.name}: expected ${bin.sha256}, got ${actualHash}`);
    }

    fs.mkdirSync(path.dirname(bin.dest), { recursive: true });
    if (bin.zipEntries) {
        console.log(`Extracting ${bin.zipEntries.length} files from archive...`);
        extractSelectedFromZip(buffer, bin.zipEntries, bin.destDir);
    } else if (bin.zipEntry) {
        console.log(`Extracting ${bin.zipEntry} from archive...`);
        extractFromZip(buffer, bin.zipEntry, bin.dest);
    } else {
        fs.writeFileSync(bin.dest, buffer);
    }

    fs.writeFileSync(versionFilePath(bin.dest), bin.version + '\n');
    console.log(`${bin.name} v${bin.version} downloaded successfully`);
}

// Downloads the prebuilt qbittorrent-nox bundle and verifies its SHA-256 checksum and Ed25519
// signature BEFORE extracting anything. Throws (and extracts nothing) on any verification failure.
async function downloadQbittorrentNox(bin) {
    if (isUpToDate(bin)) {
        console.log(`${bin.name} v${bin.version} already up to date, skipping`);
        return;
    }

    console.log(`Downloading ${bin.name} v${bin.version}...`);
    const [zipBuffer, sumsText, sigBase64] = await Promise.all([
        downloadBufferWithRetry(bin.zipUrl),
        downloadBufferWithRetry(bin.sumsUrl).then((b) => b.toString('utf8')),
        downloadBufferWithRetry(bin.sigUrl).then((b) => b.toString('utf8')),
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
    extractSelectedFromZip,
    downloadBin,
    downloadQbittorrentNox,
    BINS,
    FRANKENPHP_FILES,
    QBITTORRENT_NOX,
    QBITTORRENT_NOX_PUBLIC_KEY,
};
