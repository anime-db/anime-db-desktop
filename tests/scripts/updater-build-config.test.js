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

/**
 * Build configuration the auto-updater depends on. Each condition fails silently at run time
 * rather than at build time: a missing module is MODULE_NOT_FOUND on start, a name with spaces
 * makes `latest.yml` and the release asset disagree (404 on download), and a missing explicit
 * `publish` leaves `app-update.yml` dependent on `.git/config` auto-detection.
 */
const fs   = require('fs');
const path = require('path');

const pkg = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'package.json'), 'utf8'));

describe('release build configuration for the updater', () => {
    test('build.files does not exclude node_modules', () => {
        const excluded = pkg.build.files.filter((entry) => entry.startsWith('!') && /node_modules|electron-updater/.test(entry));

        expect(excluded).toEqual([]);
    });

    test('electron-updater is a production dependency', () => {
        expect(pkg.dependencies).toHaveProperty('electron-updater');
    });

    test('the installer file name has no spaces', () => {
        const name = pkg.build.nsis.artifactName;

        expect(typeof name).toBe('string');
        expect(name).not.toMatch(/\s/);
    });

    test('build.publish points at the GitHub repository explicitly', () => {
        expect(pkg.build.publish).toEqual({
            provider: 'github',
            owner:    'anime-db',
            repo:     'anime-db-desktop',
        });
    });

    test('the build script still never publishes', () => {
        expect(pkg.scripts.build).toContain('--publish never');
    });
});
