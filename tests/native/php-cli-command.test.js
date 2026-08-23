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

const { buildPhpCliArgs } = require('../../native/supervisor/php-cli-command');

// Mirrors app/tests/Unit/Service/Plugin/PhpCliCommandTest.php on the PHP side. Both encode the
// same rule, so both need the same cases: the two representations drifting apart is exactly the
// failure this module was extracted to prevent.
describe('buildPhpCliArgs', () => {
    /**
     * Paths here use forward slashes even though the packaged binary only ever runs on Windows:
     * `path.basename()` follows the host platform, so a `C:\...\frankenphp.exe` literal resolves
     * as one long basename on the Linux runner these tests run on and would fail to match. That
     * is a property of the test environment, not of the code — on Windows either form works.
     */
    test('inserts the php-cli subcommand for the packaged FrankenPHP binary', () => {
        expect(buildPhpCliArgs('C:/app/bin/frankenphp/frankenphp.exe', 'bin/console', 'cache:warmup'))
            .toEqual(['C:/app/bin/frankenphp/frankenphp.exe', 'php-cli', 'bin/console', 'cache:warmup']);
    });

    test('inserts the php-cli subcommand for a FrankenPHP binary without the .exe suffix', () => {
        expect(buildPhpCliArgs('/opt/bin/frankenphp', 'bin/console'))
            .toEqual(['/opt/bin/frankenphp', 'php-cli', 'bin/console']);
    });

    test('matches the binary name case-insensitively', () => {
        expect(buildPhpCliArgs('/opt/bin/FrankenPHP.EXE', 'bin/console'))
            .toEqual(['/opt/bin/FrankenPHP.EXE', 'php-cli', 'bin/console']);
    });

    test('leaves a plain PHP binary untouched', () => {
        expect(buildPhpCliArgs('/usr/bin/php', '-l', 'plugin.php'))
            .toEqual(['/usr/bin/php', '-l', 'plugin.php']);
    });

    /**
     * The name has to match whole, not merely contain "frankenphp": a binary called
     * `frankenphp-wrapper` is not the packaged FrankenPHP and must not get the subcommand.
     */
    test('does not match a binary whose name merely contains frankenphp', () => {
        expect(buildPhpCliArgs('/usr/bin/frankenphp-wrapper', 'bin/console'))
            .toEqual(['/usr/bin/frankenphp-wrapper', 'bin/console']);
    });

    test('works with no arguments at all', () => {
        expect(buildPhpCliArgs('/opt/bin/frankenphp')).toEqual(['/opt/bin/frankenphp', 'php-cli']);
    });
});
