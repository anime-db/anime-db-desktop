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
const fs     = require('fs');
const path   = require('path');
const { app } = require('electron');
const paths  = require('../paths');

const VERSIONS_JSON  = path.join(__dirname, '..', '..', 'scripts', 'versions.json');
const MIGRATIONS_DIR = path.join(__dirname, '..', '..', 'app', 'migrations');

/**
 * Отпечаток текущей сборки: app.getVersion() + содержимое scripts/versions.json + список файлов
 * app/migrations/. app.getVersion() одной себя недостаточно (issue #386) — package.json version
 * при релизной сборке ниоткуда не обновляется, поэтому сборка может смениться, а версия — нет.
 *
 * @returns {string}
 */
function computeBuildFingerprint() {
    const hash = crypto.createHash('sha256');
    hash.update(app.getVersion());
    hash.update(fs.readFileSync(VERSIONS_JSON, 'utf8'));
    hash.update(fs.readdirSync(MIGRATIONS_DIR).sort().join(','));
    return hash.digest('hex');
}

/**
 * @returns {string | null}
 */
function readStoredFingerprint() {
    const statePath = paths.getStatePath();
    if (!fs.existsSync(statePath)) return null;

    try {
        const parsed = JSON.parse(fs.readFileSync(statePath, 'utf8'));
        return (parsed !== null && typeof parsed === 'object' && typeof parsed.buildFingerprint === 'string')
            ? parsed.buildFingerprint
            : null;
    } catch {
        return null;
    }
}

/**
 * @param {string} fingerprint
 */
function writeStoredFingerprint(fingerprint) {
    const statePath = paths.getStatePath();
    fs.mkdirSync(path.dirname(statePath), { recursive: true });
    fs.writeFileSync(statePath, JSON.stringify({ buildFingerprint: fingerprint }, null, 2));
}

/**
 * Удаляет скомпилированный кэш Symfony-контейнера (APP_RUNTIME_DIR/cache), когда отпечаток
 * текущей сборки отличается от сохранённого при предыдущем запуске (issue #386): APP_ENV=prod
 * отключает у Symfony проверку свежести ConfigCache, поэтому при установке новой сборки поверх
 * старой устаревший дамп контейнера и *.bundles.php иначе переживают обновление и грузятся
 * против нового кода. APP_RUNTIME_DIR/log не трогается — там уже есть отдельная ротация.
 * На первом запуске (сохранённого отпечатка ещё нет) только сохраняет текущий, ничего не удаляя.
 */
function invalidateStaleCache() {
    const current = computeBuildFingerprint();
    const stored  = readStoredFingerprint();

    if (stored === current) return;

    if (stored !== null) {
        fs.rmSync(path.join(paths.getRuntimeDir(), 'cache'), {
            recursive: true,
            force: true,
            maxRetries: 3,
            retryDelay: 200,
        });
    }
    writeStoredFingerprint(current);
}

module.exports = { invalidateStaleCache, computeBuildFingerprint };
