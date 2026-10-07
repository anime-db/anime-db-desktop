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
 * Electron-free helpers shared by scripts/shots/run.js (parent) and capture.js (child): the
 * run timeout, the "last page" marker the child prints and the parent parses, and the
 * failure artifacts (PNG + DOM dump) of the page a run died on.
 */

const fs   = require('fs');
const path = require('path');

const LAST_PAGE_MARKER = '[shots:page] ';

// Well below the 20 minutes `timeout-minutes` of the CI job, so the run reports itself first.
const DEFAULT_TIMEOUT_MS = 10 * 60 * 1000;
// Pause between SIGTERM (child may save the failed page) and SIGKILL.
const KILL_GRACE_MS = 5000;
// Upper bound for taking the failure snapshot of a page that may itself be hung.
const SNAPSHOT_TIMEOUT_MS = 3000;

const UNKNOWN_PAGE = 'не определена';

/**
 * @param {NodeJS.ProcessEnv} env
 * @returns {number}
 */
function resolveTimeoutMs(env) {
    const value = Number(env.SHOTS_TIMEOUT_MS);
    return Number.isFinite(value) && value > 0 ? value : DEFAULT_TIMEOUT_MS;
}

/**
 * @param {string} page
 * @returns {string}
 */
function formatLastPageLine(page) {
    return `${LAST_PAGE_MARKER}${page}`;
}

/**
 * Feeds on raw stdout chunks and remembers the page named by the latest marker line.
 */
class LastPageTracker {
    constructor() {
        this.page = null;
        this.buffer = '';
    }

    /**
     * @param {string|Buffer} chunk
     */
    push(chunk) {
        this.buffer += chunk;
        const lines = this.buffer.split('\n');
        this.buffer = lines.pop();
        for (const line of lines) {
            this.#scan(line);
        }
    }

    /**
     * @returns {string}
     */
    describe() {
        this.#scan(this.buffer);
        return this.page === null ? UNKNOWN_PAGE : this.page;
    }

    #scan(line) {
        const trimmed = line.trim();
        if (trimmed.startsWith(LAST_PAGE_MARKER)) {
            this.page = trimmed.slice(LAST_PAGE_MARKER.length);
        }
    }
}

/**
 * @param {number} timeoutMs
 * @param {string} lastPage
 * @returns {string}
 */
function formatTimeoutMessage(timeoutMs, lastPage) {
    return `прогон снимков превысил таймаут ${Math.round(timeoutMs / 1000)} с, последняя страница: ${lastPage}`;
}

/**
 * Saves `<name>.FAILED.png` and `<name>.FAILED.html` of the current page. A failure of the
 * snapshot itself is logged and never thrown, so it cannot mask the original error.
 *
 * @param {{ capturePage: () => Promise<{toPNG: () => Buffer}>, executeJavaScript: (code: string) => Promise<string> }} webContents
 * @param {string} dir
 * @param {string} name
 * @param {number} [timeoutMs]
 * @returns {Promise<void>}
 */
async function saveFailureArtifacts(webContents, dir, name, timeoutMs = SNAPSHOT_TIMEOUT_MS) {
    const withTimeout = (promise) => Promise.race([
        promise,
        new Promise((_, reject) => setTimeout(() => reject(new Error('timed out')), timeoutMs).unref()),
    ]);

    try {
        fs.mkdirSync(dir, { recursive: true });
    } catch (err) {
        console.error(`[shots] cannot create ${dir}: ${err.message}`);
        return;
    }

    try {
        const image = await withTimeout(webContents.capturePage());
        fs.writeFileSync(path.join(dir, `${name}.FAILED.png`), image.toPNG());
    } catch (err) {
        console.error(`[shots] failed to save snapshot of ${name}: ${err.message}`);
    }

    try {
        const html = await withTimeout(webContents.executeJavaScript('document.documentElement.outerHTML'));
        fs.writeFileSync(path.join(dir, `${name}.FAILED.html`), String(html));
    } catch (err) {
        console.error(`[shots] failed to save DOM dump of ${name}: ${err.message}`);
    }
}

module.exports = {
    LAST_PAGE_MARKER,
    DEFAULT_TIMEOUT_MS,
    KILL_GRACE_MS,
    UNKNOWN_PAGE,
    resolveTimeoutMs,
    formatLastPageLine,
    formatTimeoutMessage,
    saveFailureArtifacts,
    LastPageTracker,
};
