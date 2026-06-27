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

const frankenphp = require('./frankenphp');
// TODO Таск 8: const meilisearch = require('./meilisearch');

/**
 * Запускает все дочерние процессы и возвращает занятые ими порты.
 *
 * @returns {Promise<{ frankenphpPort: number }>}
 */
async function start() {
    const frankenphpPort = await frankenphp.start();
    // TODO Таск 8: const meiliPort = await meilisearch.start();
    return { frankenphpPort };
}

/**
 * Останавливает все дочерние процессы.
 */
function stop() {
    frankenphp.stop();
    // TODO Таск 8: meilisearch.stop();
}

module.exports = { start, stop };
