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

const COMMAND = 'app:market:refresh';

/**
 * Upper bound for `app:market:refresh` (issue #440): a network fetch of the plugin registry
 * (app.market.http_client's own max_duration is 60s, see app/config/services.yaml) plus signature
 * verification and writing the snapshot — generous headroom over that 60s ceiling for the rest of
 * the command's own work.
 */
const TIMEOUT_MS = 90 * 1000;

/**
 * Runs `bin/console app:market:refresh` once and resolves when it exits. Callers must not await
 * this from inside supervisor.start() (see index.js) — the whole point of the startup trigger
 * (issue #440, epic #435 decision №5) is that appearing window must not wait on a network fetch,
 * only the render-fallback and future "Check for updates" button already need the result awaited.
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<void>}
 */
function run(context) {
    return phpCommand.run(COMMAND, [], context, TIMEOUT_MS);
}

module.exports = { run };
