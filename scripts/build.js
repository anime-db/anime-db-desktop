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

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const appDir = path.join(rootDir, 'app');
const packageJsonPath = path.join(rootDir, 'package.json');

const RELEASE_TAG_PATTERN = /^v\d+\.\d+\.\d+(-[0-9A-Za-z-.]+)?(\+[0-9A-Za-z-.]+)?$/;

/**
 * Stamps package.json's "version" from the GITHUB_REF_NAME env var — set automatically by
 * GitHub Actions on every run, no workflow changes needed — when the run was triggered by a
 * release tag ("v1.2.3" -> "1.2.3", including semver pre-release/build tags like "v1.0.0-rc1").
 * No-op outside of a tag build (workflow_dispatch, local runs), leaving the checked-in version
 * untouched. A tag build whose ref doesn't parse as a release tag throws instead of silently
 * keeping the checked-in version, since the build trigger accepts a wider tag pattern than this
 * function parses.
 */
function syncVersionFromTag(
    refName = process.env.GITHUB_REF_NAME,
    refType = process.env.GITHUB_REF_TYPE,
    pkgPath = packageJsonPath
) {
    if (refType !== 'tag') return;

    if (!refName || !RELEASE_TAG_PATTERN.test(refName)) {
        throw new Error(
            `Release tag "${refName}" does not match the expected "vX.Y.Z" release version format.`
        );
    }

    const version = refName.slice(1);
    const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
    if (pkg.version === version) return;

    pkg.version = version;
    fs.writeFileSync(pkgPath, JSON.stringify(pkg, null, 4) + '\n', 'utf8');
    console.log(`Stamped package.json version from tag: ${version}`);
}

function main() {
    syncVersionFromTag();

    execSync('composer install --no-dev --optimize-autoloader', { cwd: appDir, stdio: 'inherit' });

    fs.rmSync(path.join(appDir, 'var', 'cache'), { recursive: true, force: true });
    fs.rmSync(path.join(appDir, 'var', 'log'), { recursive: true, force: true });

    const jsDir = path.join(appDir, 'public', 'js');
    fs.mkdirSync(jsDir, { recursive: true });
    fs.copyFileSync(
        path.resolve(rootDir, 'node_modules', 'htmx.org', 'dist', 'htmx.min.js'),
        path.join(jsDir, 'htmx.min.js')
    );
}

if (require.main === module) {
    main();
}

module.exports = { syncVersionFromTag };
