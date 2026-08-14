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

const phpCommand = require('./php-command');

const COMMAND = 'app:search:reindex';

/**
 * Upper bound for a full catalog reindex (see AnimeReindexService). Rebuilding the Meilisearch
 * index from the SQLite catalog can take much longer than the other one-off console calls in
 * native/supervisor/, so it gets its own, more generous timeout rather than sharing one with
 * them.
 */
const TIMEOUT_MS = 30 * 60 * 1000;

/**
 * Runs `bin/console app:search:reindex` once and resolves when it exits successfully. Used to
 * rebuild the Meilisearch index after it was wiped by a version change (see meilisearch.js
 * checkVersionAndWipe / issue #389).
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<void>}
 */
function run(context) {
    return phpCommand.run(COMMAND, [], context, TIMEOUT_MS);
}

module.exports = { run };
