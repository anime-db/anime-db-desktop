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

const fs = require('fs');
const os = require('os');
const path = require('path');
const { checkDependencyClosure, checkUpdateMetadata, checkReleaseBuildFlag } = require('../../scripts/check-packaged-app');

let tmp;

beforeEach(() => {
    tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'check-packaged-app-'));
});

afterEach(() => {
    fs.rmSync(tmp, { recursive: true, force: true });
});

function writePkg(dir, pkg, entry = 'index.js') {
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(path.join(dir, 'package.json'), JSON.stringify(pkg));
    if (entry !== null) {
        fs.mkdirSync(path.dirname(path.join(dir, entry)), { recursive: true });
        fs.writeFileSync(path.join(dir, entry), '');
    }
}

describe('checkDependencyClosure', () => {
    function appWith(deps) {
        const appDir = path.join(tmp, 'app');
        writePkg(appDir, { name: 'app', dependencies: deps }, null);
        return appDir;
    }

    test('passes when hoisted and nested dependencies are all inside the app', () => {
        const appDir = appWith({ updater: '1' });
        writePkg(path.join(appDir, 'node_modules', 'updater'), { main: 'out/main.js', dependencies: { runtime: '1', semver: '7' } }, 'out/main.js');
        writePkg(path.join(appDir, 'node_modules', 'runtime'), { dependencies: { semver: '6' } });
        writePkg(path.join(appDir, 'node_modules', 'semver'), {});
        writePkg(path.join(appDir, 'node_modules', 'runtime', 'node_modules', 'semver'), {});

        expect(checkDependencyClosure(appDir)).toEqual([]);
    });

    test('resolves a dependency that exists only in the nested node_modules of its dependent', () => {
        const appDir = appWith({ updater: '1' });
        writePkg(path.join(appDir, 'node_modules', 'updater'), { dependencies: { runtime: '1' } });
        writePkg(path.join(appDir, 'node_modules', 'runtime'), { dependencies: { 'only-nested': '1' } });
        writePkg(path.join(appDir, 'node_modules', 'runtime', 'node_modules', 'only-nested'), {});

        expect(checkDependencyClosure(appDir)).toEqual([]);
    });

    test('does not resolve a dependency from the nested node_modules of another package', () => {
        const appDir = appWith({ updater: '1', other: '1' });
        writePkg(path.join(appDir, 'node_modules', 'updater'), { dependencies: { x: '1' } });
        writePkg(path.join(appDir, 'node_modules', 'other'), { dependencies: { x: '1' } });
        writePkg(path.join(appDir, 'node_modules', 'other', 'node_modules', 'x'), {});

        expect(checkDependencyClosure(appDir)).toEqual(['x (required by updater) is not in the packaged app']);
    });

    test('reports a missing hoisted transitive dependency', () => {
        const appDir = appWith({ updater: '1' });
        writePkg(path.join(appDir, 'node_modules', 'updater'), { dependencies: { 'fs-extra': '1' } });

        expect(checkDependencyClosure(appDir)).toEqual(['fs-extra (required by updater) is not in the packaged app']);
    });

    test('does not resolve above the packaged app, unlike Node on the CI runner', () => {
        const appDir = appWith({ updater: '1' });
        writePkg(path.join(tmp, 'node_modules', 'updater'), {});

        expect(checkDependencyClosure(appDir)).toEqual(['updater (required by app) is not in the packaged app']);
    });

    test('reports a package whose entry point is missing', () => {
        const appDir = appWith({ updater: '1' });
        writePkg(path.join(appDir, 'node_modules', 'updater'), { main: 'out/main.js' }, null);

        expect(checkDependencyClosure(appDir)).toEqual(['updater: entry point "out/main.js" is missing in node_modules/updater']);
    });

    test('tolerates a missing optional dependency', () => {
        const appDir = appWith({ updater: '1' });
        writePkg(path.join(appDir, 'node_modules', 'updater'), { optionalDependencies: { native: '1' } });

        expect(checkDependencyClosure(appDir)).toEqual([]);
    });

    test('resolves scoped packages', () => {
        const appDir = appWith({ '@scope/a': '1' });
        writePkg(path.join(appDir, 'node_modules', '@scope', 'a'), { dependencies: { b: '1' } });
        writePkg(path.join(appDir, 'node_modules', 'b'), {});

        expect(checkDependencyClosure(appDir)).toEqual([]);
    });
});

describe('checkUpdateMetadata', () => {
    const installer = 'AnimeDB-Setup-0.2.0.exe';

    function dist(latestYml, files) {
        if (latestYml !== null) {
            fs.writeFileSync(path.join(tmp, 'latest.yml'), latestYml);
        }
        for (const file of files) {
            fs.writeFileSync(path.join(tmp, file), '');
        }
        return tmp;
    }

    const yml = (name, legacyPath = name) => `version: 0.2.0\nfiles:\n  - url: ${name}\n    sha512: x\n    size: 1\npath: ${legacyPath}\nsha512: x\n`;

    test('passes when latest.yml names an installer that exists with its blockmap', () => {
        expect(checkUpdateMetadata(dist(yml(installer), [installer, `${installer}.blockmap`]))).toEqual([]);
    });

    test('reports a missing latest.yml', () => {
        expect(checkUpdateMetadata(dist(null, [installer]))).toEqual(['latest.yml is missing: installed copies would not see this release']);
    });

    test('reports a missing installer and blockmap', () => {
        expect(checkUpdateMetadata(dist(yml(installer), []))).toEqual([
            `installer "${installer}" named in latest.yml is not in ${tmp}`,
            `blockmap "${installer}.blockmap" is not in ${tmp}`,
        ]);
    });

    test('reports a missing blockmap next to an existing installer', () => {
        expect(checkUpdateMetadata(dist(yml(installer), [installer]))).toEqual([`blockmap "${installer}.blockmap" is not in ${tmp}`]);
    });

    test('reports latest.yml that names no installer', () => {
        expect(checkUpdateMetadata(dist('version: 0.2.0\nsha512: x\n', [installer, `${installer}.blockmap`]))).toEqual([
            'latest.yml names no installer: neither files[].url with an .exe nor "path"',
        ]);
    });

    test('checks files[].url, which electron-updater downloads, not the legacy path', () => {
        const stale = 'AnimeDB-Setup-0.1.0.exe';

        expect(checkUpdateMetadata(dist(yml(installer, stale), [stale, `${stale}.blockmap`]))).toEqual([
            `latest.yml is inconsistent: "path" ${stale} is not among files[].url (${installer})`,
            `installer "${installer}" named in latest.yml is not in ${tmp}`,
            `blockmap "${installer}.blockmap" is not in ${tmp}`,
        ]);
    });

    test('falls back to the legacy path when latest.yml has no files', () => {
        expect(checkUpdateMetadata(dist(`version: 0.2.0\npath: ${installer}\nsha512: x\n`, [installer]))).toEqual([`blockmap "${installer}.blockmap" is not in ${tmp}`]);
    });

    test.each([
        ['single', `'${installer}'`],
        ['double', `"${installer}"`],
    ])('reads a path in %s quotes', (_, quoted) => {
        expect(checkUpdateMetadata(dist(`version: 0.2.0\nfiles:\n  - url: ${quoted}\npath: ${quoted}\nsha512: x\n`, [installer, `${installer}.blockmap`]))).toEqual([]);
    });

    test('reports an installer name with spaces', () => {
        const spaced = 'AnimeDB Setup 0.2.0.exe';
        const problems = checkUpdateMetadata(dist(yml(spaced), [spaced, `${spaced}.blockmap`]));

        expect(problems).toEqual([`installer name "${spaced}" contains whitespace: GitHub renames such assets and the download would 404`]);
    });
});

describe('checkReleaseBuildFlag', () => {
    function app(pkg) {
        const appDir = path.join(tmp, 'app');
        writePkg(appDir, pkg, null);
        return appDir;
    }

    test('passes on a tag build with releaseBuild true and the tag version', () => {
        expect(checkReleaseBuildFlag(app({ version: '1.2.3', releaseBuild: true }), 'tag', 'v1.2.3')).toEqual([]);
    });

    test('passes on a non-tag build with releaseBuild false', () => {
        expect(checkReleaseBuildFlag(app({ version: '0.0.1', releaseBuild: false }), 'branch', 'master')).toEqual([]);
        expect(checkReleaseBuildFlag(app({ version: '0.0.1', releaseBuild: false }), undefined, undefined)).toEqual([]);
    });

    test('reports releaseBuild false on a tag build', () => {
        expect(checkReleaseBuildFlag(app({ version: '1.2.3', releaseBuild: false }), 'tag', 'v1.2.3')).toEqual([
            'releaseBuild is false in the packaged package.json: expected true on tag build v1.2.3, the auto-updater would be disabled',
        ]);
    });

    test('reports a missing releaseBuild on a tag build', () => {
        expect(checkReleaseBuildFlag(app({ version: '1.2.3' }), 'tag', 'v1.2.3')).toEqual([
            'releaseBuild is undefined in the packaged package.json: expected true on tag build v1.2.3, the auto-updater would be disabled',
        ]);
    });

    test('reports a version that differs from the tag', () => {
        const problems = checkReleaseBuildFlag(app({ version: '1.2.2', releaseBuild: true }), 'tag', 'v1.2.3');

        expect(problems).toEqual(['version "1.2.2" in the packaged package.json does not match tag v1.2.3']);
    });

    test('reports releaseBuild true on a non-tag build', () => {
        expect(checkReleaseBuildFlag(app({ version: '0.0.1', releaseBuild: true }), 'branch', 'master')).toEqual(['releaseBuild is true in the packaged package.json: expected false on a non-tag build']);
    });

    test('reports a missing releaseBuild on a non-tag build', () => {
        expect(checkReleaseBuildFlag(app({ version: '0.0.1' }), 'branch', 'master')).toEqual(['releaseBuild is undefined in the packaged package.json: expected false on a non-tag build']);
    });

    test('reports both problems on a tag build with releaseBuild false and a wrong version', () => {
        expect(checkReleaseBuildFlag(app({ version: '1.2.2', releaseBuild: false }), 'tag', 'v1.2.3')).toEqual([
            'releaseBuild is false in the packaged package.json: expected true on tag build v1.2.3, the auto-updater would be disabled',
            'version "1.2.2" in the packaged package.json does not match tag v1.2.3',
        ]);
    });
});
