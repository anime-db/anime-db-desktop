/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

'use strict';

const https = require('https');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const versions = JSON.parse(fs.readFileSync(path.resolve(__dirname, 'versions.json'), 'utf8'));
const binDir = path.resolve(__dirname, '..', 'bin');

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

function versionFilePath(dest) {
    return path.join(path.dirname(dest), '.version');
}

function isUpToDate(dest, version) {
    if (!fs.existsSync(dest)) return false;
    const vf = versionFilePath(dest);
    if (!fs.existsSync(vf)) return false;
    return fs.readFileSync(vf, 'utf8').trim() === version;
}

function download(url, destPath) {
    return new Promise((resolve, reject) => {
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
            }).on('error', reject);
        };
        follow(url);
    });
}

function extractFromZip(zipBuffer, targetFilename, destPath) {
    // Locate End of Central Directory (signature 0x06054b50)
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

        if (path.basename(fileName.replace(/\\/g, '/')) === targetFilename) {
            if (zipBuffer.readUInt32LE(lhOffset) !== 0x04034b50) {
                throw new Error('Invalid ZIP: bad Local File Header signature');
            }
            const lhFnLen = zipBuffer.readUInt16LE(lhOffset + 26);
            const lhExtraLen = zipBuffer.readUInt16LE(lhOffset + 28);
            const dataStart = lhOffset + 30 + lhFnLen + lhExtraLen;
            const compressed = zipBuffer.slice(dataStart, dataStart + compressedSize);

            let data;
            if (method === 0) {
                data = compressed;
            } else if (method === 8) {
                data = zlib.inflateRawSync(compressed);
            } else {
                throw new Error(`Unsupported ZIP compression method: ${method}`);
            }

            fs.mkdirSync(path.dirname(destPath), { recursive: true });
            fs.writeFileSync(destPath, data);
            return;
        }

        offset += 46 + fnLen + extraLen + commentLen;
    }

    throw new Error(`${targetFilename} not found in ZIP`);
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

(async () => {
    for (const bin of BINS) {
        await downloadBin(bin);
    }
})().catch((err) => {
    console.error('download-bins failed:', err.message);
    process.exit(1);
});
