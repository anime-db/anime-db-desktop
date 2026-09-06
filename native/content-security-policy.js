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

const { app, session } = require('electron');

/**
 * Plugin assets (CSS/JS) are served by the app itself as same-origin static files behind a
 * manifest-driven <link>/<script src> tag (see PluginAssetController) — there is no inline plugin
 * script or style left anywhere in the templates, so script-src/style-src need no 'unsafe-inline',
 * nonce or hash exception. img-src allows https: for source favicons rendered from arbitrary
 * domains (anime/show.html.twig), and connect-src allows ws: for the scan-progress WebSocket.
 *
 * @type {string}
 */
const CONTENT_SECURITY_POLICY = [
    "default-src 'self'",
    "script-src 'self'",
    "style-src 'self'",
    "img-src 'self' app-media: https:",
    "connect-src 'self' ws:",
    "font-src 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'none'",
].join('; ');

/**
 * Only the main window's own backend origin (http://127.0.0.1:{port}) gets the policy. The splash
 * window loads splash.html via file:// and carries its own, more permissive meta-tag policy
 * (issue #594) — file:// responses must be left untouched or the two policies would combine and
 * break splash's inline script/style.
 *
 * @param {string} url
 * @returns {boolean}
 */
function isLocalBackendUrl(url) {
    try {
        return new URL(url).hostname === '127.0.0.1';
    } catch {
        return false;
    }
}

/**
 * Adds the Content-Security-Policy header to responses served by the local backend. Set from the
 * main process (not a Twig meta tag) so page content cannot strip or override it.
 *
 * @param {import('electron').OnHeadersReceivedListenerDetails} details
 * @param {(response: import('electron').HeadersReceivedResponse) => void} callback
 */
function addContentSecurityPolicy(details, callback) {
    if (!isLocalBackendUrl(details.url)) {
        callback({ responseHeaders: details.responseHeaders });
        return;
    }

    callback({
        responseHeaders: {
            ...details.responseHeaders,
            'Content-Security-Policy': [CONTENT_SECURITY_POLICY],
        },
    });
}

app.whenReady().then(() => {
    session.defaultSession.webRequest.onHeadersReceived(addContentSecurityPolicy);
});

module.exports = { addContentSecurityPolicy, CONTENT_SECURITY_POLICY };
