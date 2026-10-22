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
 * Three checks:
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
 *    installer named in its files[].url (the top-level `path` is a legacy fallback), so latest.yml
 *    must exist and name an installer that exists in dist/ along with its blockmap. A silently missing latest.yml would publish a green release that no
 *    installed copy can see.
 *
 * 3. Release build flag. The packaged resources/app/package.json must carry the "releaseBuild"
 *    flag the auto-updater is gated on (native/updater): on a tag build (GITHUB_REF_TYPE=tag) it
 *    must be true and "version" must equal GITHUB_REF_NAME without the "v" prefix; otherwise it
 *    must be false. A flag lost on the way to packaging would ship a release whose updater is
 *    silently off, and the next release would never reach those copies.
 *
 * 4. Update keys. native/updater/update-keys.json in the packaged app must be a non-empty array of
 *    base64 keys, each exactly 32 bytes after decoding: a broken file means no signed update is
 *    ever accepted by this build.
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
    const yml = fs.readFileSync(latest, 'utf8');
    const unquote = (value) => value.trim().replace(/^(['"])(.*)\1$/, '$2');
    // electron-updater 6 downloads what files[].url names (Provider.getFileList, NsisUpdater picks
    // the first .exe there); the top-level `path` is only a fallback for metadata without `files`.
    // latest.yml is written by electron-builder in a fixed shape, so a line match is enough here
    // and keeps this script free of a YAML dependency.
    const urls = [...yml.matchAll(/^\s+-\s+url:(.+)$/gm)].map((m) => unquote(m[1]));
    const pathMatch = yml.match(/^path:(.+)$/m);
    const legacyPath = pathMatch === null ? null : unquote(pathMatch[1]);

    const problems = [];
    const installers = urls.length > 0 ? urls.filter((url) => url.endsWith('.exe')) : [legacyPath].filter((p) => p !== null);
    if (installers.length === 0) {
        return ['latest.yml names no installer: neither files[].url with an .exe nor "path"'];
    }
    if (urls.length > 0 && legacyPath !== null && !urls.includes(legacyPath)) {
        problems.push(`latest.yml is inconsistent: "path" ${legacyPath} is not among files[].url (${urls.join(', ')})`);
    }
    for (const installer of installers) {
        if (/\s/.test(installer)) {
            problems.push(`installer name "${installer}" contains whitespace: GitHub renames such assets and the download would 404`);
        }
        if (!fs.existsSync(path.join(distDir, installer))) {
            problems.push(`installer "${installer}" named in latest.yml is not in ${distDir}`);
        }
        if (!fs.existsSync(path.join(distDir, `${installer}.blockmap`))) {
            problems.push(`blockmap "${installer}.blockmap" is not in ${distDir}`);
        }
    }
    return problems;
}

/**
 * @returns {string[]} problems found; empty when releaseBuild and version agree with the CI ref
 */
function checkReleaseBuildFlag(appDir, refType = process.env.GITHUB_REF_TYPE, refName = process.env.GITHUB_REF_NAME) {
    const pkg = readJson(path.join(appDir, 'package.json'));
    if (refType !== 'tag') {
        return pkg.releaseBuild === false ? [] : [`releaseBuild is ${JSON.stringify(pkg.releaseBuild)} in the packaged package.json: expected false on a non-tag build`];
    }

    const problems = [];
    if (pkg.releaseBuild !== true) {
        problems.push(`releaseBuild is ${JSON.stringify(pkg.releaseBuild)} in the packaged package.json: expected true on tag build ${refName}, the auto-updater would be disabled`);
    }
    const expectedVersion = (refName ?? '').replace(/^v/, '');
    if (pkg.version !== expectedVersion) {
        problems.push(`version ${JSON.stringify(pkg.version)} in the packaged package.json does not match tag ${refName}`);
    }
    return problems;
}

/**
 * @returns {string[]} problems found; empty when update-keys.json holds only 32-byte keys
 */
function checkUpdateKeys(appDir) {
    const file = path.join(appDir, 'native', 'updater', 'update-keys.json');
    let keys;
    try {
        keys = readJson(file);
    } catch (e) {
        return [`update-keys.json cannot be read from the packaged app (${path.relative(appDir, file)}): ${e.message}`];
    }
    if (!Array.isArray(keys) || keys.length === 0) {
        return ['update-keys.json in the packaged app is not a non-empty array: no signed update would be accepted'];
    }
    const problems = [];
    keys.forEach((key, index) => {
        const bytes = typeof key === 'string' && /^[A-Za-z0-9+/]+={0,2}$/.test(key) ? Buffer.from(key, 'base64').length : null;
        if (bytes !== 32) {
            problems.push(`update-keys.json key #${index} is not a base64 32-byte Ed25519 key (decoded: ${bytes === null ? 'not base64' : `${bytes} bytes`})`);
        }
    });
    return problems;
}

function main(distDir = path.join(rootDir, 'dist')) {
    const appDir = path.join(distDir, 'win-unpacked', 'resources', 'app');
    const problems = [...checkDependencyClosure(appDir), ...checkUpdateMetadata(distDir), ...checkReleaseBuildFlag(appDir), ...checkUpdateKeys(appDir)];
    for (const problem of problems) {
        console.error(`::error::${problem}`);
    }
    if (problems.length === 0) {
        console.log('Packaged app: dependency closure complete, latest.yml matches installer and blockmap, releaseBuild flag is correct, update keys are valid.');
    }
    return problems.length === 0 ? 0 : 1;
}

if (require.main === module) {
    process.exitCode = main(process.argv[2]);
}

module.exports = { checkDependencyClosure, checkUpdateMetadata, checkReleaseBuildFlag, checkUpdateKeys, resolvePackageDir };
