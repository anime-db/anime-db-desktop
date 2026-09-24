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
 * Collects page-level failures for scripts/shots/capture.js — deliberately free of any
 * `electron` dependency so it can be unit-tested outside Electron (see tests/scripts/). capture.js
 * feeds it raw `console-message` and `webRequest` events; this module decides which of them are
 * failures and formats the run's verdict.
 *
 * Console level considered a failure: only 'error'. Chromium routes both an uncaught exception/
 * unhandled promise rejection and an app `console.error()` call (see app/assets/js/controller.js,
 * which uses it to report a broken UI control) through this level, so it is the one level that
 * reliably means "something is actually wrong" rather than routine diagnostic noise — 'warning'
 * covers things like deprecation notices that are not page defects.
 */
const FAILURE_CONSOLE_LEVELS = new Set(['error']);

class PageErrorTracker {
    constructor() {
        /** @type {{ page: string, reason: string }[]} */
        this.failures = [];
    }

    /**
     * @param {string} pageUrl
     * @param {{ level: string, message: string }} details `console-message` event payload
     */
    recordConsoleMessage(pageUrl, { level, message }) {
        if (!FAILURE_CONSOLE_LEVELS.has(level)) {
            return;
        }
        this.failures.push({ page: pageUrl, reason: `console.${level}: ${message}` });
    }

    /**
     * @param {string} pageUrl
     * @param {{ resourceType: string, url: string, statusCode?: number, error?: string }} details
     *   a failed `webRequest` (onCompleted with a non-2xx status, or onErrorOccurred)
     */
    recordFailedResource(pageUrl, { resourceType, url, statusCode, error }) {
        // onCompleted carries both fields (error is 'net::OK'); onErrorOccurred has no statusCode.
        const detail = typeof statusCode === 'number' && statusCode >= 400 ? `HTTP ${statusCode}` : error;
        this.failures.push({ page: pageUrl, reason: `failed to load ${resourceType} ${url}: ${detail}` });
    }

    /**
     * @param {string} pageUrl
     * @param {string} reason
     */
    recordFailure(pageUrl, reason) {
        this.failures.push({ page: pageUrl, reason });
    }

    /**
     * @returns {boolean}
     */
    hasFailures() {
        return this.failures.length > 0;
    }

    /**
     * @returns {string}
     */
    formatReport() {
        return this.failures.map(({ page, reason }) => `${page}: ${reason}`).join('\n');
    }
}

module.exports = { PageErrorTracker, FAILURE_CONSOLE_LEVELS };
