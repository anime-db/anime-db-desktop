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

const fs   = require('fs');
const path = require('path');
const paths = require('./paths');
const { todayStr } = require('./supervisor/logrotate');

/**
 * Синхронно дописывает стек ошибки в лог главного процесса (APP_RUNTIME_DIR/log/main-<дата>.log).
 * Дочерние процессы уже логируются через logrotate.js в своих супервизорах, но у главного процесса
 * своего лога не было — console.error() в собранном GUI-приложении никуда не попадает (issue
 * #390, отзыв ревьюера). Best-effort: если запись не удалась (например, каталог недоступен),
 * молча продолжаем — показать диалог и выйти важнее, чем сам факт логирования.
 *
 * @param {Error} err
 */
function logCrash(err) {
    try {
        const logDir = path.join(paths.getRuntimeDir(), 'log');
        fs.mkdirSync(logDir, { recursive: true });
        const file = path.join(logDir, `main-${todayStr()}.log`);
        fs.appendFileSync(file, `[${new Date().toISOString()}] ${err && err.stack ? err.stack : String(err)}\n`);
    } catch {
        // см. комментарий выше — лог необязателен, диалог и выход обязательны
    }
}

module.exports = { logCrash };
