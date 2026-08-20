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

const fs   = require('fs');
const path = require('path');
const vm   = require('vm');

const SPLASH_HTML = fs.readFileSync(path.join(__dirname, '..', '..', 'native', 'splash', 'splash.html'), 'utf8');

/**
 * There is no jsdom in this project's Jest setup (testEnvironment: 'node', tests/native/), so
 * this runs the real inline <script> from splash.html in a sandbox with a minimal DOM stub —
 * exercising the actual lang/dir assignment logic instead of only asserting on markup text.
 *
 * @param {{ locale: string, dir: string }} splash
 * @returns {{ lang: string, dir: string }} the resulting documentElement state
 */
function runSplashScript(splash) {
    const [, scriptBody] = SPLASH_HTML.match(/<script>([\s\S]*?)<\/script>/);

    const documentElement = { lang: '', dir: '' };
    const elements = {
        logo:   { src: '' },
        bar:    { style: {} },
        status: { textContent: '' },
    };

    const sandbox = {
        window: { splash },
        document: {
            documentElement,
            getElementById: (id) => elements[id],
        },
    };
    vm.createContext(sandbox);
    vm.runInContext(scriptBody, sandbox);

    return documentElement;
}

// issue #450: direction is resolved from the locale by a static core table, the same one
// createSplash() (native/window/splash.js) feeds through as "--splash-dir" — this test does not
// depend on any translation plugin being installed, only on window.splash.dir already being
// resolved by the time this script runs.
describe('splash.html inline script', () => {
    test.each([
        ['ru', 'ltr'],
        ['en', 'ltr'],
        ['ar', 'rtl'],
        ['he', 'rtl'],
    ])('sets documentElement lang/dir from window.splash for locale "%s"', (locale, dir) => {
        const documentElement = runSplashScript({ locale, dir, logoPath: '', initialStatus: '', onProgress: () => {} });

        expect(documentElement.lang).toBe(locale);
        expect(documentElement.dir).toBe(dir);
    });
});
