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
const os   = require('os');
const path = require('path');

const {
    run,
    parseArgs,
    fingerprintDirectory,
    findRuntimeBinary,
    diffFacts,
    RuntimeUnavailableError,
} = require('../../scripts/check-runtime-parity');

const REPO_SNAPSHOT_PATH = path.join(__dirname, '..', '..', 'scripts', 'frankenphp-runtime.json');
const REPO_VERSIONS_PATH = path.join(__dirname, '..', '..', 'scripts', 'versions.json');

// Canned reply from the fake "frankenphp php-cli -r '<code>'" call. Mirrors the shape
// scripts/check-runtime-parity.js's FACTS_CODE actually emits, so `run()` never has to spawn a
// real binary in tests (this repo's tests run under system PHP on Linux — see .claude-docs/index.md).
function baseFacts() {
    return {
        extensions: ['Core', 'intl', 'mbstring'],
        icuVersion: '77.1',
        cldrVersion: '46',
        localeEndonyms: { en: 'English', ru: 'русский', de: 'Deutsch', ja: '日本語' },
        ini: { 'date.timezone': 'UTC' },
    };
}

function makeExec(factsByCall) {
    let call = 0;
    return () => JSON.stringify(factsByCall[Math.min(call++, factsByCall.length - 1)]);
}

function tmpDir(prefix) {
    return fs.mkdtempSync(path.join(os.tmpdir(), prefix));
}

// Builds a minimal multi-file runtime bundle, mirroring FRANKENPHP_FILES in scripts/download-bins.js
// (frankenphp.exe + a DLL alongside it + one extension DLL under ext/), so the fingerprint test
// covers a nested path, not just the top-level binary.
function createFixtureRuntime(dir) {
    fs.mkdirSync(path.join(dir, 'ext'), { recursive: true });
    fs.writeFileSync(path.join(dir, 'frankenphp.exe'), 'binary-content');
    fs.writeFileSync(path.join(dir, 'php8ts.dll'), 'runtime-dll');
    fs.writeFileSync(path.join(dir, 'ext', 'php_intl.dll'), 'intl-extension');
    fs.writeFileSync(path.join(dir, '.version'), '1.12.4');
}

describe('findRuntimeBinary', () => {
    test('returns null when the runtime directory does not exist', () => {
        expect(findRuntimeBinary(path.join(os.tmpdir(), 'anime-db-does-not-exist'))).toBeNull();
    });

    test('returns null when the runtime directory exists but is empty', () => {
        const dir = tmpDir('runtime-empty-');
        expect(findRuntimeBinary(dir)).toBeNull();
    });

    test('finds the binary case-insensitively, with or without .exe', () => {
        const dir = tmpDir('runtime-binary-');
        fs.writeFileSync(path.join(dir, 'FrankenPHP.EXE'), '');
        expect(findRuntimeBinary(dir)).toBe(path.join(dir, 'FrankenPHP.EXE'));
    });
});

describe('fingerprintDirectory', () => {
    test('hashes every file recursively, using forward-slash relative paths, and skips dotfiles', () => {
        const dir = tmpDir('runtime-fingerprint-');
        createFixtureRuntime(dir);

        const fingerprint = fingerprintDirectory(dir);

        expect(Object.keys(fingerprint).sort()).toEqual(['ext/php_intl.dll', 'frankenphp.exe', 'php8ts.dll']);
        expect(fingerprint['frankenphp.exe']).toHaveLength(64); // sha256 hex digest
        expect(fingerprint['.version']).toBeUndefined();
    });

    test('a missing or an added file changes the fingerprint', () => {
        const dir = tmpDir('runtime-fingerprint-diff-');
        createFixtureRuntime(dir);
        const complete = fingerprintDirectory(dir);

        fs.rmSync(path.join(dir, 'ext', 'php_intl.dll'));
        const missingFile = fingerprintDirectory(dir);
        expect(missingFile).not.toEqual(complete);
        expect(missingFile['ext/php_intl.dll']).toBeUndefined();

        fs.writeFileSync(path.join(dir, 'ext', 'php_curl.dll'), 'unexpected-extra-file');
        const extraFile = fingerprintDirectory(dir);
        expect(extraFile).not.toEqual(complete);
        expect(extraFile['ext/php_curl.dll']).toBeDefined();
    });
});

describe('diffFacts', () => {
    test('reports nothing when both sides agree', () => {
        const facts = {
            frankenphpVersion: '1.12.4',
            directoryFingerprint: { 'frankenphp.exe': 'aaa' },
            extensions: ['intl', 'mbstring'],
            icu: { version: '77.1', cldrVersion: '46' },
            localeEndonyms: { en: 'English' },
            ini: { 'date.timezone': 'UTC' },
        };
        expect(diffFacts(facts, facts)).toEqual([]);
    });

    test('reports a directory fingerprint mismatch for a missing file', () => {
        const expected = { directoryFingerprint: { 'frankenphp.exe': 'aaa', 'ext/php_intl.dll': 'bbb' } };
        const actual = { directoryFingerprint: { 'frankenphp.exe': 'aaa' } };
        expect(diffFacts(expected, actual)).toEqual([
            'directoryFingerprint["ext/php_intl.dll"]: expected "bbb", got (missing)',
        ]);
    });

    test('reports an added/removed extension', () => {
        const expected = { extensions: ['intl', 'mbstring'] };
        const actual = { extensions: ['mbstring', 'curl'] };
        expect(diffFacts(expected, actual)).toEqual([
            'extensions: missing "intl"',
            'extensions: unexpected "curl"',
        ]);
    });

    test('reports an ICU version mismatch', () => {
        const expected = { icu: { version: '77.1', cldrVersion: '46' } };
        const actual = { icu: { version: '78.2', cldrVersion: '46' } };
        expect(diffFacts(expected, actual)).toEqual([
            'icu.version: expected "77.1", got "78.2"',
        ]);
    });

    test('reports a CLDR version mismatch', () => {
        const expected = { icu: { version: '77.1', cldrVersion: '46' } };
        const actual = { icu: { version: '77.1', cldrVersion: '47' } };
        expect(diffFacts(expected, actual)).toEqual([
            'icu.cldrVersion: expected "46", got "47"',
        ]);
    });

    test('reports a FrankenPHP version mismatch', () => {
        const expected = { frankenphpVersion: '1.12.4' };
        const actual = { frankenphpVersion: '1.12.5' };
        expect(diffFacts(expected, actual)).toEqual([
            'frankenphpVersion: expected "1.12.4", got "1.12.5"',
        ]);
    });

    test('reports a locale endonym mismatch', () => {
        const expected = { localeEndonyms: { en: 'English', ru: 'русский' } };
        const actual = { localeEndonyms: { en: 'English', ru: 'Russian' } };
        expect(diffFacts(expected, actual)).toEqual([
            'localeEndonyms["ru"]: expected "русский", got "Russian"',
        ]);
    });

    test('reports an ini directive mismatch', () => {
        const expected = { ini: { 'date.timezone': 'UTC' } };
        const actual = { ini: { 'date.timezone': 'Europe/Moscow' } };
        expect(diffFacts(expected, actual)).toEqual([
            'ini["date.timezone"]: expected "UTC", got "Europe/Moscow"',
        ]);
    });
});

describe('parseArgs', () => {
    test('reads --write, --runtime-dir, --snapshot and --versions', () => {
        expect(parseArgs(['--write', '--runtime-dir', '/r', '--snapshot', '/s.json', '--versions', '/v.json']))
            .toEqual({ write: true, runtimeDir: '/r', snapshotPath: '/s.json', versionsPath: '/v.json' });
    });

    test('defaults to no options for an empty argv', () => {
        expect(parseArgs([])).toEqual({});
    });
});

describe('run', () => {
    let runtimeDir;
    let snapshotPath;

    beforeEach(() => {
        runtimeDir = tmpDir('runtime-run-');
        createFixtureRuntime(runtimeDir);
        snapshotPath = path.join(tmpDir('snapshot-run-'), 'frankenphp-runtime.json');
    });

    test('--write records a snapshot the very next compare run matches', () => {
        const exec = makeExec([baseFacts(), baseFacts()]);

        const written = run({ runtimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, write: true, exec });
        expect(written.ok).toBe(true);
        expect(written.exitCode).toBe(0);
        expect(fs.existsSync(snapshotPath)).toBe(true);

        const compared = run({ runtimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, exec });
        expect(compared).toEqual({ ok: true, exitCode: 0, message: 'Рантайм соответствует слепку.', diff: [] });
    });

    test('detects a directory composition mismatch: a file removed from the runtime after the snapshot was written', () => {
        const exec = makeExec([baseFacts(), baseFacts()]);
        run({ runtimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, write: true, exec });

        fs.rmSync(path.join(runtimeDir, 'ext', 'php_intl.dll'));

        const result = run({ runtimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, exec });
        expect(result.ok).toBe(false);
        expect(result.exitCode).toBe(1);
        expect(result.diff.some((line) => line.startsWith('directoryFingerprint["ext/php_intl.dll"]'))).toBe(true);
    });

    test('detects a directory composition mismatch: a file added to the runtime after the snapshot was written', () => {
        const exec = makeExec([baseFacts(), baseFacts()]);
        run({ runtimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, write: true, exec });

        fs.writeFileSync(path.join(runtimeDir, 'ext', 'php_curl.dll'), 'unpinned-extra-extension');

        const result = run({ runtimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, exec });
        expect(result.ok).toBe(false);
        expect(result.diff.some((line) => line.startsWith('directoryFingerprint["ext/php_curl.dll"]'))).toBe(true);
    });

    test('detects an extension set mismatch', () => {
        const withoutIntl = { ...baseFacts(), extensions: ['Core', 'mbstring'] };
        const exec = makeExec([baseFacts(), withoutIntl]);
        run({ runtimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, write: true, exec });

        const result = run({ runtimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, exec });
        expect(result.ok).toBe(false);
        expect(result.diff).toContain('extensions: missing "intl"');
    });

    test('detects an ICU version mismatch', () => {
        const bumpedIcu = { ...baseFacts(), icuVersion: '78.2' };
        const exec = makeExec([baseFacts(), bumpedIcu]);
        run({ runtimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, write: true, exec });

        const result = run({ runtimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, exec });
        expect(result.ok).toBe(false);
        expect(result.diff).toContain('icu.version: expected "77.1", got "78.2"');
    });

    test('gives a plain-language message, not a stack trace, when the runtime is missing from disk', () => {
        const missingRuntimeDir = path.join(os.tmpdir(), 'anime-db-runtime-never-downloaded');
        const exec = makeExec([baseFacts()]);

        const result = run({ runtimeDir: missingRuntimeDir, snapshotPath, versionsPath: REPO_VERSIONS_PATH, exec });

        expect(result.ok).toBe(false);
        expect(result.exitCode).toBe(1);
        expect(result.message).toContain('npm run download-bins');
    });

    test('collectRuntimeFacts throws RuntimeUnavailableError (not a generic exception) for a missing runtime', () => {
        const { collectRuntimeFacts } = require('../../scripts/check-runtime-parity');
        expect(() => collectRuntimeFacts(path.join(os.tmpdir(), 'anime-db-runtime-never-downloaded'), () => ''))
            .toThrow(RuntimeUnavailableError);
    });

    test('gives a plain-language message when no snapshot file exists yet', () => {
        const exec = makeExec([baseFacts()]);
        const neverWritten = path.join(tmpDir('snapshot-missing-'), 'frankenphp-runtime.json');

        const result = run({ runtimeDir, snapshotPath: neverWritten, versionsPath: REPO_VERSIONS_PATH, exec });

        expect(result.ok).toBe(false);
        expect(result.exitCode).toBe(1);
        expect(result.message).toContain('--write');
    });

    /**
     * Builds its own stub rather than pointing at REPO_SNAPSHOT_PATH. That file was a stub only
     * until the Windows job captured a real snapshot from the packaged runtime; asserting against
     * it tied this test to the repository's data rather than to the behaviour under test, and it
     * broke the moment the real values landed. The behaviour — refuse to compare against a
     * snapshot nobody has filled in — is what matters, and it needs a fixture, not the live file.
     */
    test('refuses to compare against an unfilled stub snapshot', () => {
        const stubPath = path.join(tmpDir('runtime-stub-snapshot-'), 'snapshot.json');
        fs.writeFileSync(stubPath, JSON.stringify({ $stub: true, frankenphpVersion: null, directoryFingerprint: {} }));
        const exec = makeExec([baseFacts()]);

        const result = run({ runtimeDir, snapshotPath: stubPath, versionsPath: REPO_VERSIONS_PATH, exec });

        expect(result.ok).toBe(false);
        expect(result.exitCode).toBe(1);
        expect(result.message).toContain('заглушка');
        expect(result.message).toContain('--write');
    });

    /**
     * The counterpart: the snapshot committed in the repository is no longer a stub, so a compare
     * against it must get as far as comparing. Guards against someone reverting it to a stub and
     * quietly turning the CI gate into a no-op.
     */
    test('the snapshot committed in the repository is filled in, not a stub', () => {
        const snapshot = JSON.parse(fs.readFileSync(REPO_SNAPSHOT_PATH, 'utf8'));

        expect(snapshot.$stub).toBe(false);
        expect(Object.keys(snapshot.directoryFingerprint).length).toBeGreaterThan(0);
        expect(snapshot.extensions).toContain('gd');
    });
});
