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

const frankenphp  = require('./frankenphp');
const meilisearch = require('./meilisearch');

/**
 * Запускает все дочерние процессы и возвращает занятые ими порты.
 * Meilisearch стартует первым — его URL/key нужны FrankenPHP в env.
 *
 * @returns {Promise<{ frankenphpPort: number, meiliPort: number }>}
 */
async function start() {
    const { port: meiliPort, key: meiliKey } = await meilisearch.start();
    const frankenphpPort = await frankenphp.start(meiliPort, meiliKey);
    return { frankenphpPort, meiliPort };
}

/**
 * Останавливает все дочерние процессы в правильном порядке:
 * сначала FrankenPHP (нет новых запросов), затем Meilisearch.
 *
 * @returns {Promise<void>}
 */
async function stop() {
    await frankenphp.stop();
    await meilisearch.stop();
}

module.exports = { start, stop };
