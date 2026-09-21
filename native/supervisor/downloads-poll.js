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

const COMMAND = 'app:downloads:poll';

/**
 * Upper bound for `app:downloads:poll` — same order of magnitude as the other one-off console
 * calls in native/supervisor/ (issue #400): one qBittorrent WebUI round trip per pending
 * infoHash, no different in kind from the other startup calls already bounded here.
 */
const TIMEOUT_MS = 30 * 1000;

/**
 * Runs `bin/console app:downloads:poll` once and resolves when it exits (issue #685). Callers
 * must not await this from inside supervisor.start() (see index.js), same rationale as
 * market-refresh.js: a download that finished while the app was closed should be linked without
 * the appearing window waiting on it — qBittorrent is a supervised sidecar that does not run at
 * all while the app is closed, so this startup run is the only thing that ever catches that case;
 * App\Scheduler\DownloadsPollSchedule's own tick does not fire until its own interval elapses.
 *
 * @param {import('./env').PhpContext} context
 * @returns {Promise<void>}
 */
function run(context) {
    return phpCommand.run(COMMAND, [], context, TIMEOUT_MS);
}

module.exports = { run };
