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
const BUILD_ID_PATH  = path.join(__dirname, '..', '..', 'scripts', 'build-id.txt');

/**
 * Отпечаток текущей сборки: app.getVersion() + scripts/build-id.txt (стемпуется на каждой сборке
 * в scripts/build.js#writeBuildId, см. там) + содержимое scripts/versions.json + список файлов
 * app/migrations/. app.getVersion() одной себя недостаточно (issue #386) — package.json version
 * стемпуется только на релизных тег-сборках (scripts/build.js#syncVersionFromTag), а сборки через
 * workflow_dispatch (единственные, что сейчас существуют) все дают одну и ту же версию.
 * build-id.txt отсутствует при незапакованном dev-запуске (`npm start` без предварительного
 * `npm run prebuild`) — в этом случае используется фиксированная заглушка, а не ошибка.
 *
 * @returns {string}
 */
function computeBuildFingerprint() {
    const hash = crypto.createHash('sha256');
    hash.update(app.getVersion());
    hash.update(fs.existsSync(BUILD_ID_PATH) ? fs.readFileSync(BUILD_ID_PATH, 'utf8') : 'dev');
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
 * Отсутствие сохранённого отпечатка (первый запуск или битый state.json) не считается сменой
 * сборки — нечего инвалидировать, commitFingerprint() создаст маркер после успешного старта.
 *
 * @returns {boolean}
 */
function hasBuildChanged() {
    const stored = readStoredFingerprint();
    return stored !== null && stored !== computeBuildFingerprint();
}

/**
 * Отличает провал вайпа кэша (диск занят, файл заблокирован антивирусом и т.п.) от прочих ошибок
 * запуска — вызывающая сторона (native/lifecycle/index.js) обязана распознавать этот класс так же,
 * как MigrationBootstrapError, и не засчитывать его в серию safe-mode: отключение плагинов не
 * освобождает занятый файл (issue #403, ревью PR #406).
 */
class CacheInvalidationError extends Error {
    constructor(message) {
        super(message);
        this.name = 'CacheInvalidationError';
    }
}

/**
 * Удаляет скомпилированный кэш Symfony-контейнера (APP_RUNTIME_DIR/cache): APP_ENV=prod отключает
 * у Symfony проверку свежести ConfigCache, поэтому устаревший дамп контейнера и *.bundles.php
 * иначе переживают установку новой сборки поверх старой и грузятся против нового кода (issue
 * #386). Вызывающая сторона обязана вызывать это до запуска любого PHP-процесса и только когда
 * hasBuildChanged() вернул true. APP_RUNTIME_DIR/log не трогается — там уже есть отдельная
 * ротация.
 *
 * @throws {CacheInvalidationError}
 */
function invalidateCache() {
    try {
        fs.rmSync(path.join(paths.getRuntimeDir(), 'cache'), {
            recursive: true,
            force: true,
            maxRetries: 3,
            retryDelay: 200,
        });
    } catch (err) {
        throw new CacheInvalidationError(err.message);
    }
}

/**
 * Сохраняет текущий отпечаток сборки как обработанный. Сливается с существующим содержимым
 * state.json вместо перезаписи файла целиком — это общее состояние приложения, в нём есть (или
 * появятся) другие поля. Вызывающая сторона обязана вызывать это только после успешного запуска
 * всех процессов — если закоммитить отпечаток заранее, а старт упадёт на середине, следующий
 * запуск сочтёт апгрейд уже обработанным и не повторит инвалидацию.
 */
function commitFingerprint() {
    const statePath = paths.getStatePath();

    let state = {};
    try {
        const parsed = JSON.parse(fs.readFileSync(statePath, 'utf8'));
        if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) state = parsed;
    } catch {
        // отсутствует или битый — начинаем с пустого состояния
    }
    state.buildFingerprint = computeBuildFingerprint();

    fs.mkdirSync(path.dirname(statePath), { recursive: true });
    fs.writeFileSync(statePath, JSON.stringify(state, null, 2));
}

module.exports = { hasBuildChanged, invalidateCache, commitFingerprint, computeBuildFingerprint, CacheInvalidationError };
