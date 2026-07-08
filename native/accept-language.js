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

const { app, session } = require('electron');
const { getOrCreateLocale, getLocale } = require('./config');

/**
 * Overrides the Accept-Language header of an outgoing request with the locale currently stored
 * in config.json. Reading it fresh on every request (instead of caching it) is what makes a
 * locale change from the settings screen apply without an app restart.
 *
 * @param {import('electron').OnBeforeSendHeadersListenerDetails} details
 * @param {(response: import('electron').BeforeSendResponse) => void} callback
 */
function overrideAcceptLanguage(details, callback) {
    details.requestHeaders['Accept-Language'] = getLocale();
    callback({ requestHeaders: details.requestHeaders });
}

app.whenReady().then(() => {
    getOrCreateLocale();

    // session.webRequest (not app.commandLine.appendSwitch('lang', ...)) so it also covers
    // page navigation and fetch() calls, and re-reads the locale on every request.
    session.defaultSession.webRequest.onBeforeSendHeaders(overrideAcceptLanguage);
});

module.exports = { overrideAcceptLanguage };
