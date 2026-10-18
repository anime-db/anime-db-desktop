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

/**
 * Release gate for what the auto-updater needs from a packaged build (issue #1092). Runs in
 * build.yml after `npm run build` and before anything is published, so a red check means "no
 * release", not "a release that installed copies cannot update from".
 *
 * Two checks:
 *
 * 1. Dependency closure. Every production dependency of the packaged app, transitively, must be
 *    resolvable INSIDE dist/win-unpacked/resources/app. A check of the electron-updater directory
 *    alone is not enough: npm hoists its own dependencies (builder-util-runtime, fs-extra, js-yaml,
 *    semver, ...) next to it, and any of them missing is the same MODULE_NOT_FOUND on the user's
 *    machine. The release gate (release-smoke) does not cover this: on the runner Node walks up from
 *    dist/win-unpacked/resources/app and finds the repository's own node_modules, which a user's
 *    machine does not have. Resolution here therefore never leaves the packaged tree.
 *
 * 2. Update metadata. electron-updater reads latest.yml of the newest release and downloads the
 *    file named in it, so latest.yml must exist and name an installer that exists in dist/ along
 *    with its blockmap. A silently missing latest.yml would publish a green release that no
 *    installed copy can see.
 */

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');

function readJson(file) {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/**
 * Finds `name` the way Node does from a package directory, but never above `appDir`.
 */
function resolvePackageDir(name, fromDir, appDir) {
    let dir = fromDir;
    for (;;) {
        const candidate = path.join(dir, 'node_modules', name);
        if (fs.existsSync(path.join(candidate, 'package.json'))) {
            return candidate;
        }
        if (dir === appDir) {
            return null;
        }
        dir = path.dirname(dir);
        // Skip "node_modules" path segments themselves: "<x>/node_modules/node_modules" is never a
        // lookup location, the lookup continues from "<x>".
        if (path.basename(dir) === 'node_modules') {
            dir = path.dirname(dir);
        }
        if (!dir.startsWith(appDir)) {
            return null;
        }
    }
}

function entryExists(pkgDir, pkg) {
    if (pkg.main === undefined && pkg.exports !== undefined) {
        // Entry is declared through "exports" only; the package.json being present is all we can
        // check without reimplementing the exports resolution.
        return true;
    }
    const main = path.join(pkgDir, pkg.main ?? 'index.js');
    return [main, `${main}.js`, path.join(main, 'index.js')].some((file) => fs.existsSync(file) && fs.statSync(file).isFile());
}

/**
 * @returns {string[]} problems found; empty when the closure is complete
 */
function checkDependencyClosure(appDir) {
    const problems = [];
    const rootPkg = readJson(path.join(appDir, 'package.json'));
    const queue = Object.keys(rootPkg.dependencies ?? {}).map((name) => ({ name, from: appDir, by: 'app', optional: false }));
    for (const name of Object.keys(rootPkg.optionalDependencies ?? {})) {
        queue.push({ name, from: appDir, by: 'app', optional: true });
    }
    const seen = new Set();

    while (queue.length > 0) {
        const { name, from, by, optional } = queue.shift();
        const pkgDir = resolvePackageDir(name, from, appDir);
        if (pkgDir === null) {
            if (!optional) {
                problems.push(`${name} (required by ${by}) is not in the packaged app`);
            }
            continue;
        }
        if (seen.has(pkgDir)) {
            continue;
        }
        seen.add(pkgDir);

        const pkg = readJson(path.join(pkgDir, 'package.json'));
        if (!entryExists(pkgDir, pkg)) {
            problems.push(`${name}: entry point "${pkg.main ?? 'index.js'}" is missing in ${path.relative(appDir, pkgDir)}`);
        }
        for (const dep of Object.keys(pkg.dependencies ?? {})) {
            queue.push({ name: dep, from: pkgDir, by: name, optional: false });
        }
        for (const dep of Object.keys(pkg.optionalDependencies ?? {})) {
            queue.push({ name: dep, from: pkgDir, by: name, optional: true });
        }
    }

    return problems;
}

/**
 * @returns {string[]} problems found; empty when latest.yml, the installer and its blockmap agree
 */
function checkUpdateMetadata(distDir) {
    const latest = path.join(distDir, 'latest.yml');
    if (!fs.existsSync(latest)) {
        return ['latest.yml is missing: installed copies would not see this release'];
    }
    const match = fs.readFileSync(latest, 'utf8').match(/^path:\s*['"]?([^'"\r\n]+?)['"]?\s*$/m);
    if (match === null) {
        return ['latest.yml has no "path" entry'];
    }
    const installer = match[1];
    const problems = [];
    if (/\s/.test(installer)) {
        problems.push(`installer name "${installer}" contains whitespace: GitHub renames such assets and the download would 404`);
    }
    if (!fs.existsSync(path.join(distDir, installer))) {
        problems.push(`installer "${installer}" named in latest.yml is not in ${distDir}`);
    }
    if (!fs.existsSync(path.join(distDir, `${installer}.blockmap`))) {
        problems.push(`blockmap "${installer}.blockmap" is not in ${distDir}`);
    }
    return problems;
}

function main(distDir = path.join(rootDir, 'dist')) {
    const appDir = path.join(distDir, 'win-unpacked', 'resources', 'app');
    const problems = [...checkDependencyClosure(appDir), ...checkUpdateMetadata(distDir)];
    for (const problem of problems) {
        console.error(`::error::${problem}`);
    }
    if (problems.length === 0) {
        console.log('Packaged app: dependency closure complete, latest.yml matches installer and blockmap.');
    }
    return problems.length === 0 ? 0 : 1;
}

if (require.main === module) {
    process.exitCode = main(process.argv[2]);
}

module.exports = { checkDependencyClosure, checkUpdateMetadata, resolvePackageDir };
