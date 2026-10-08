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

/*
 * Скачивает Linux-сборку FrankenPHP запиненной версии в `bin/frankenphp/frankenphp` — то, что
 * нужно прогону E2E (`npm run e2e`) и чего нет в `download-bins.js`: тот собирает поставочный
 * состав под Windows (frankenphp.exe плюс DLL), а здесь нужен один самодостаточный бинарь под
 * раннер CI и под Linux-машину разработчика.
 *
 * Версия — из `scripts/versions.json`, та же, что у поставочного рантайма: прогон E2E имеет смысл
 * только на том PHP, который получит пользователь (issue #536). Контрольная сумма — оттуда же,
 * `sha256.frankenphpLinux`; апстрим для этого ассета `checksums.txt` не публикует, поэтому сумма
 * записана по первой загрузке (как и `sha256.frankenphp` для Windows-архива) и защищает от
 * подмены ассета под уже выпущенным тегом, а не от компрометации самой сборки.
 */

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const { downloadBufferWithRetry } = require('./download-bins');

const versions = JSON.parse(fs.readFileSync(path.resolve(__dirname, 'versions.json'), 'utf8'));
const rootDir = path.resolve(__dirname, '..');

const ASSET_NAME = 'frankenphp-linux-x86_64';
const dest = path.join(rootDir, 'bin', 'frankenphp', 'frankenphp');

/**
 * @param {Buffer} buffer
 * @returns {string}
 */
function sha256(buffer) {
    return crypto.createHash('sha256').update(buffer).digest('hex');
}

async function main() {
    if (process.platform !== 'linux') {
        throw new Error('this binary is only usable on Linux; the shipped Windows runtime comes from download-bins.js.');
    }

    const expected = versions.sha256.frankenphpLinux;
    if (typeof expected !== 'string' || expected === '') {
        throw new Error('scripts/versions.json has no sha256.frankenphpLinux — add it together with the version bump.');
    }

    // Уже скачанный бинарь не перекачивается, но и не принимается на веру: сверяется по сумме,
    // иначе файл от прошлого пина молча остался бы в каталоге и прогон пошёл бы не на том PHP.
    if (fs.existsSync(dest) && sha256(fs.readFileSync(dest)) === expected) {
        console.log(`FrankenPHP ${versions.frankenphp} (linux) is already in ${dest}`);

        return;
    }

    const url = assetUrl();
    console.log(`Downloading ${url}`);
    const buffer = await downloadBufferWithRetry(url);

    const actual = sha256(buffer);
    if (actual !== expected) {
        throw new Error(`SHA-256 mismatch for ${ASSET_NAME}: expected ${expected}, got ${actual}`);
    }

    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.writeFileSync(dest, buffer);
    fs.chmodSync(dest, 0o755);
    console.log(`FrankenPHP ${versions.frankenphp} (linux) written to ${dest}`);
}

function assetUrl() {
    return `https://github.com/php/frankenphp/releases/download/v${versions.frankenphp}/${ASSET_NAME}`;
}

if (require.main === module) {
    main().catch((err) => {
        console.error(`\n[download-e2e-runtime] ${err.message}\n`);
        process.exit(1);
    });
}

module.exports = { main, assetUrl, sha256, dest, ASSET_NAME };
