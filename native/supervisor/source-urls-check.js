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

const COMMAND = 'app:source-urls:check';

/**
 * Upper bound for `app:source-urls:check`: it hashes the link-rules files of the enabled plugins,
 * compares the hash with the one stored in data.db and, on a mismatch, queues one message.
 */
const TIMEOUT_MS = 60 * 1000;

/**
 * Runs `bin/console app:source-urls:check` once and resolves when it exits. The command queues a
 * recompute of `anime_source.normalized_url` when the fingerprint of the link-rule set differs from
 * the one stored in data.db. Called on every start, after the migrations and after the messenger
 * transports exist; the call is not awaited by supervisor.start().
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<void>}
 */
function run(context) {
    return phpCommand.run(COMMAND, [], context, TIMEOUT_MS);
}

module.exports = { run };
