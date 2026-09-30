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

const { nativeTheme } = require('electron');
const { getThemePreference } = require('./config');

/**
 * Backend event name (see App\Controller\SettingsController::THEME_CHANGED_EVENT on the PHP
 * side, published from setTheme()) that lifecycle/index.js listens for over /ws to call
 * applyThemeSource() again without an app restart. Keep this string in sync with the PHP side —
 * a mismatch breaks live-apply silently, the same lesson as PROXY_CHANGED_EVENT (issue #336),
 * which is why theme.test.js cross-checks both sides against each other.
 *
 * @type {string}
 */
const THEME_CHANGED_EVENT = 'theme.changed';

/**
 * body-bg colors Bootstrap 5.3 uses for the light and dark color modes. app/assets/scss/_palette.scss
 * overrides $primary, $danger, $body-color and $border-color, but not body-bg, so these two
 * literals must be kept in sync with the palette by hand if that ever changes.
 *
 * @type {{ light: string, dark: string }}
 */
const BACKGROUND_COLOR = Object.freeze({
    light: '#ffffff',
    dark: '#212529',
});

/**
 * @param {boolean} isDark
 * @returns {string}
 */
function getBackgroundColor(isDark) {
    return isDark ? BACKGROUND_COLOR.dark : BACKGROUND_COLOR.light;
}

/**
 * Reads themePreference from config.json (written by AppSettingsProvider on the PHP side) and
 * applies it to nativeTheme.themeSource. Call once at startup, before any window is created, and
 * again whenever the backend notifies of a settings change (backend-event "theme.changed" over
 * /ws) — the payload is ignored, config.json is re-read instead, same pattern as
 * native/proxy.js#applyProxy().
 */
function applyThemeSource() {
    nativeTheme.themeSource = getThemePreference();
}

/**
 * Registers the nativeTheme 'updated' listener that keeps every live window's background color in
 * sync with the current color scheme. This fires both when themePreference changes (applyThemeSource()
 * above re-assigns themeSource) and when the OS theme itself changes while themeSource is 'system'.
 *
 * @param {() => import('electron').BrowserWindow[]} getWindows
 */
function registerThemeUpdateHandler(getWindows) {
    nativeTheme.on('updated', () => {
        const color = getBackgroundColor(nativeTheme.shouldUseDarkColors);
        for (const win of getWindows()) {
            if (!win.isDestroyed()) {
                win.setBackgroundColor(color);
            }
        }
    });
}

module.exports = {
    THEME_CHANGED_EVENT,
    getBackgroundColor,
    applyThemeSource,
    registerThemeUpdateHandler,
};
