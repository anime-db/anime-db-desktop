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
// Printed once by capture.js: the pid of the Electron main process, the only one that may
// be asked to save the failed page (see RunWatchdog).
const PID_MARKER = '[shots:pid] ';

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
        this.pid = null;
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
        } else if (trimmed.startsWith(PID_MARKER)) {
            const pid = Number(trimmed.slice(PID_MARKER.length));
            this.pid = Number.isInteger(pid) && pid > 0 ? pid : null;
        }
    }
}

/**
 * Run timeout state machine: on timeout asks the Electron main process (and only it) to save
 * the failed page, after the grace period SIGKILLs the whole process group. Signals and timers
 * are injected, so the escalation is testable without real processes.
 */
class RunWatchdog {
    /**
     * @param {object} options
     * @param {number} options.timeoutMs
     * @param {number} [options.graceMs]
     * @param {() => void} options.requestSnapshot  signals the Electron main process only
     * @param {(signal: string) => void} options.killGroup  signals the whole process group
     * @param {() => void} [options.onTimeout]
     */
    constructor({ timeoutMs, graceMs = KILL_GRACE_MS, requestSnapshot, killGroup, onTimeout = () => {} }) {
        this.timeoutMs = timeoutMs;
        this.graceMs = graceMs;
        this.requestSnapshot = requestSnapshot;
        this.killGroup = killGroup;
        this.onTimeout = onTimeout;
        this.timedOut = false;
        this.timer = null;
        this.killTimer = null;
    }

    start() {
        this.timer = setTimeout(() => {
            this.timedOut = true;
            this.onTimeout();
            this.requestSnapshot();
            this.killTimer = setTimeout(() => this.killGroup('SIGKILL'), this.graceMs);
        }, this.timeoutMs);
    }

    /**
     * @param {number|null} code  exit code of the child
     * @returns {number} exit code of the run; a timed-out run is never green
     */
    finish(code) {
        clearTimeout(this.timer);
        clearTimeout(this.killTimer);
        if (this.timedOut) {
            // Whatever is left in the group (e.g. Xvfb) must not outlive the run.
            this.killGroup('SIGKILL');
            return 1;
        }
        return code === null ? 1 : code;
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
    PID_MARKER,
    RunWatchdog,
    DEFAULT_TIMEOUT_MS,
    KILL_GRACE_MS,
    UNKNOWN_PAGE,
    resolveTimeoutMs,
    formatLastPageLine,
    formatTimeoutMessage,
    saveFailureArtifacts,
    LastPageTracker,
};
