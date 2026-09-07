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
const { main: buildAssets } = require('./build-assets');

const rootDir = path.resolve(__dirname, '..');
const appDir = path.join(rootDir, 'app');
const packageJsonPath = path.join(rootDir, 'package.json');
const buildIdPath = path.join(rootDir, 'scripts', 'build-id.txt');

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

/**
 * Stamps a value that changes on every build into scripts/build-id.txt — packaged with the app
 * (see package.json "build.files") and hashed into the runtime cache-invalidation fingerprint
 * (native/supervisor/cache-invalidation.js). app.getVersion() alone only changes on tagged
 * release builds (see syncVersionFromTag above); plain workflow_dispatch/branch builds would
 * otherwise be indistinguishable from a previous install of the same checked-in version and skip
 * cache invalidation on upgrade (issue #386). GITHUB_SHA/GITHUB_RUN_ID are set by GitHub Actions
 * automatically on every run — no workflow change needed. Local runs (no CI env) fall back to a
 * timestamp so every local build is still treated as distinct.
 */
function writeBuildId(buildIdOutPath = buildIdPath) {
    const id = process.env.GITHUB_SHA
        ? `${process.env.GITHUB_SHA}-${process.env.GITHUB_RUN_ID}`
        : `local-${Date.now()}`;
    fs.writeFileSync(buildIdOutPath, id, 'utf8');
}

function main() {
    syncVersionFromTag();
    writeBuildId();

    execSync('composer install --no-dev --optimize-autoloader', { cwd: appDir, stdio: 'inherit' });

    fs.rmSync(path.join(appDir, 'var', 'cache'), { recursive: true, force: true });
    fs.rmSync(path.join(appDir, 'var', 'log'), { recursive: true, force: true });

    // Фронтенд-ассеты собираются отдельным скриптом, потому что их нужно уметь собрать и без
    // остальной части этого шага: `npm start` зовёт только его. Тащить сюда весь prebuild
    // нельзя — он делает `composer install --no-dev` и снёс бы dev-зависимости.
    buildAssets();
}

if (require.main === module) {
    main();
}

module.exports = { syncVersionFromTag, writeBuildId };
