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

jest.mock('child_process', () => ({ execSync: jest.fn() }));
jest.mock('../../scripts/build-assets', () => ({ main: jest.fn() }));

const { execSync } = require('child_process');
const { syncVersionFromTag, writeBuildId, generateInstallerPlugins, main } = require('../../scripts/build');

function writePackageJson(version) {
    const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'build-test-')), 'package.json');
    fs.writeFileSync(file, JSON.stringify({ name: 'anime-db-desktop', version }, null, 4) + '\n', 'utf8');
    return file;
}

function buildIdFilePath() {
    return path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'build-id-test-')), 'build-id.txt');
}

describe('syncVersionFromTag', () => {
    test('stamps the version from a "v*.*.*" tag ref', () => {
        const pkgPath = writePackageJson('0.0.1');
        syncVersionFromTag('v1.2.3', 'tag', pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('1.2.3');
    });

    test('stamps a semver pre-release tag ref', () => {
        const pkgPath = writePackageJson('0.0.1');
        syncVersionFromTag('v1.0.0-rc1', 'tag', pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('1.0.0-rc1');
    });

    test('leaves the version untouched when the run was not triggered by a tag (branch build)', () => {
        const pkgPath = writePackageJson('0.0.1');
        syncVersionFromTag('master', 'branch', pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('0.0.1');
    });

    test('leaves the version untouched when ref type is undefined (local run / workflow_dispatch)', () => {
        const pkgPath = writePackageJson('0.0.1');
        syncVersionFromTag(undefined, undefined, pkgPath);
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('0.0.1');
    });

    test('is a no-op when the tag already matches the current version', () => {
        const pkgPath = writePackageJson('1.2.3');
        const before = fs.statSync(pkgPath).mtimeMs;
        syncVersionFromTag('v1.2.3', 'tag', pkgPath);
        expect(fs.statSync(pkgPath).mtimeMs).toBe(before);
    });

    test('throws when a tag build ref does not parse as a release version', () => {
        const pkgPath = writePackageJson('0.0.1');
        expect(() => syncVersionFromTag('v1.2.3.4', 'tag', pkgPath)).toThrow(
            /does not match the expected/
        );
        expect(JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version).toBe('0.0.1');
    });
});

describe('writeBuildId', () => {
    const ORIGINAL_ENV = { ...process.env };

    afterEach(() => {
        process.env = { ...ORIGINAL_ENV };
    });

    test('writes GITHUB_SHA + GITHUB_RUN_ID when running in GitHub Actions (tag or workflow_dispatch alike)', () => {
        process.env.GITHUB_SHA = 'abc123';
        process.env.GITHUB_RUN_ID = '999';
        const idPath = buildIdFilePath();

        writeBuildId(idPath);

        expect(fs.readFileSync(idPath, 'utf8')).toBe('abc123-999');
    });

    test('falls back to a local timestamp marker outside of CI', () => {
        delete process.env.GITHUB_SHA;
        delete process.env.GITHUB_RUN_ID;
        const idPath = buildIdFilePath();

        writeBuildId(idPath);

        expect(fs.readFileSync(idPath, 'utf8')).toMatch(/^local-\d+$/);
    });
});

describe('generateInstallerPlugins', () => {
    function appDirWithCache() {
        const appPath = fs.mkdtempSync(path.join(os.tmpdir(), 'build-app-test-'));
        fs.mkdirSync(path.join(appPath, 'var', 'cache', 'prod'), { recursive: true });
        return appPath;
    }

    test('runs the console command and removes the Symfony cache it created', () => {
        const appPath = appDirWithCache();
        const run = jest.fn();

        generateInstallerPlugins(run, appPath);

        expect(run).toHaveBeenCalledTimes(1);
        expect(run.mock.calls[0][0]).toMatch(/bin\/console app:installer:plugins/);
        expect(run.mock.calls[0][1]).toMatchObject({ cwd: appPath });
        expect(fs.existsSync(path.join(appPath, 'var', 'cache'))).toBe(false);
    });

    test('propagates a failing exit code and still removes the cache', () => {
        const appPath = appDirWithCache();
        const run = jest.fn(() => {
            throw new Error('Command failed');
        });

        expect(() => generateInstallerPlugins(run, appPath)).toThrow('Command failed');
        expect(fs.existsSync(path.join(appPath, 'var', 'cache'))).toBe(false);
    });
});

describe('main', () => {
    const ORIGINAL_ENV = { ...process.env };

    let sandbox;

    beforeEach(() => {
        execSync.mockReset();
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'build-main-test-'));
        sandbox = {
            appPath: path.join(root, 'app'),
            pkgPath: writePackageJson('0.0.1'),
            buildIdOutPath: path.join(root, 'build-id.txt'),
        };
        fs.mkdirSync(path.join(sandbox.appPath, 'var', 'cache'), { recursive: true });
        fs.mkdirSync(path.join(sandbox.appPath, 'var', 'log'), { recursive: true });
        delete process.env.GITHUB_REF_TYPE;
        delete process.env.GITHUB_REF_NAME;
    });

    afterEach(() => {
        process.env = { ...ORIGINAL_ENV };
    });

    test('generates the installer plugin list after "composer install --no-dev"', () => {
        main(sandbox);

        const commands = execSync.mock.calls.map((call) => call[0]);
        const composer = commands.findIndex((c) => c.startsWith('composer install --no-dev'));
        const generate = commands.findIndex((c) => c.includes('app:installer:plugins'));
        expect(composer).toBeGreaterThanOrEqual(0);
        expect(generate).toBeGreaterThan(composer);
    });

    test('a failing generation fails the build before assets are built', () => {
        const { main: buildAssets } = require('../../scripts/build-assets');
        buildAssets.mockClear();
        execSync.mockImplementation((command) => {
            if (command.includes('app:installer:plugins')) throw new Error('registry is not fresh');
        });

        expect(() => main(sandbox)).toThrow('registry is not fresh');
        expect(buildAssets).not.toHaveBeenCalled();
    });
});
