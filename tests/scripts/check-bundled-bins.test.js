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

const { run, parseArgs, BundledBinsError, LAUNCH_TIMEOUT_MS } = require('../../scripts/check-bundled-bins');

const VERSIONS = { meilisearch: '1.13.0', qbittorrentNox: '5.2.3_2', ffprobe: '9.0.2_1' };
const NAMES = ['meilisearch', 'qbittorrent-nox', 'ffprobe'];

describe('check-bundled-bins', () => {
    let tmpDir;
    let binDir;
    let snapshotPath;
    let versionsPath;

    // Prints a plausible `--version` line for whichever bin is being launched.
    function okExec() {
        return jest.fn((command) => {
            if (command.includes('qbittorrent-nox')) return 'qBittorrent v5.2.3\n';
            if (command.includes('ffprobe')) return 'ffprobe version 9.0.2 Copyright\nmore\n';
            return 'meilisearch 1.13.0\n';
        });
    }

    function opts(extra = {}) {
        return { binDir, snapshotPath, versionsPath, platform: 'win32', exec: okExec(), ...extra };
    }

    beforeEach(() => {
        tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'check-bundled-bins-'));
        binDir = path.join(tmpDir, 'bin');
        snapshotPath = path.join(tmpDir, 'snapshot.json');
        versionsPath = path.join(tmpDir, 'versions.json');
        fs.writeFileSync(versionsPath, JSON.stringify(VERSIONS));

        for (const name of NAMES) {
            fs.mkdirSync(path.join(binDir, name, 'sub'), { recursive: true });
            fs.writeFileSync(path.join(binDir, name, `${name}.exe`), `exe-${name}`);
            fs.writeFileSync(path.join(binDir, name, 'sub', 'lib.dll'), `dll-${name}`);
            fs.writeFileSync(path.join(binDir, name, '.version'), 'marker\n');
        }
    });

    afterEach(() => {
        fs.rmSync(tmpDir, { recursive: true, force: true });
    });

    function writeSnapshot() {
        const exec = jest.fn();
        const result = run(opts({ write: true, exec, platform: 'linux' }));
        expect(result.ok).toBe(true);
        return exec;
    }

    test('passes when the composition matches the snapshot and the output satisfies the rule', () => {
        writeSnapshot();

        const result = run(opts());

        expect(result).toMatchObject({ ok: true, exitCode: 0, diff: [] });
        expect(result.launched).toHaveLength(3);
        NAMES.forEach((name, i) => expect(result.launched[i]).toMatch(new RegExp(`^${name}: \\S`)));
    });

    test('--no-launch reports no launched lines', () => {
        writeSnapshot();

        expect(run(opts({ noLaunch: true })).launched).toEqual([]);
    });

    test('--write records exactly version and directoryFingerprint per section, skipping dotfiles', () => {
        writeSnapshot();
        const snapshot = JSON.parse(fs.readFileSync(snapshotPath, 'utf8'));

        expect(snapshot.$stub).toBeUndefined();
        expect(Object.keys(snapshot.ffprobe)).toEqual(['version', 'directoryFingerprint']);
        expect(snapshot['qbittorrent-nox'].version).toBe('5.2.3_2');
        expect(Object.keys(snapshot.meilisearch.directoryFingerprint)).toEqual(['meilisearch.exe', 'sub/lib.dll']);
    });

    test('--write does not launch anything and works on any platform', () => {
        const exec = writeSnapshot();

        expect(exec).not.toHaveBeenCalled();
    });

    test('fails with a diff on an extra file', () => {
        writeSnapshot();
        fs.writeFileSync(path.join(binDir, 'ffprobe', 'extra.dll'), 'x');

        const result = run(opts({ noLaunch: true }));

        expect(result.ok).toBe(false);
        expect(result.diff.join('\n')).toMatch(/ffprobe\.directoryFingerprint\["extra\.dll"\].*got/);
    });

    test('fails with a diff on a missing file', () => {
        writeSnapshot();
        fs.rmSync(path.join(binDir, 'qbittorrent-nox', 'sub', 'lib.dll'));

        const result = run(opts({ noLaunch: true }));

        expect(result.ok).toBe(false);
        expect(result.diff.join('\n')).toMatch(/qbittorrent-nox\.directoryFingerprint\["sub\/lib\.dll"\].*\(missing\)/);
    });

    test('fails with a diff on a modified file', () => {
        writeSnapshot();
        fs.writeFileSync(path.join(binDir, 'meilisearch', 'meilisearch.exe'), 'changed');

        const result = run(opts({ noLaunch: true }));

        expect(result.ok).toBe(false);
        expect(result.diff.join('\n')).toMatch(/meilisearch\.directoryFingerprint\["meilisearch\.exe"\]/);
    });

    test('fails when the snapshot version differs from the pin', () => {
        writeSnapshot();
        fs.writeFileSync(versionsPath, JSON.stringify({ ...VERSIONS, qbittorrentNox: '5.2.4_1' }));

        const result = run(opts({ noLaunch: true }));

        expect(result.ok).toBe(false);
        expect(result.diff.join('\n')).toMatch(/qbittorrent-nox\.version/);
    });

    test('fails with a "write the snapshot" message when there is no snapshot', () => {
        const result = run(opts({ noLaunch: true }));

        expect(result.ok).toBe(false);
        expect(result.message).toMatch(/Запиши слепок/);
    });

    test('fails when the first output line is empty', () => {
        writeSnapshot();
        const exec = jest.fn(() => '\nmeilisearch 1.13.0\n');

        const result = run(opts({ exec }));

        expect(result.ok).toBe(false);
        expect(result.diff.join('\n')).toMatch(/первая строка вывода пустая/);
    });

    test('fails when the first output line lacks the upstream version', () => {
        writeSnapshot();
        const exec = jest.fn((command) => (command.includes('ffprobe') ? 'ffprobe version 1.0.0\n' : 'meilisearch 1.13.0 qBittorrent 5.2.3\n'));

        const result = run(opts({ exec }));

        expect(result.ok).toBe(false);
        expect(result.diff).toHaveLength(1);
        expect(result.diff[0]).toMatch(/ffprobe.*9\.0\.2/);
    });

    test('fails when a launch exits non-zero', () => {
        writeSnapshot();
        const exec = jest.fn(() => {
            throw new Error('Command failed');
        });

        const result = run(opts({ exec }));

        expect(result.ok).toBe(false);
        expect(result.diff.join('\n')).toMatch(/запуск не удался/);
    });

    test('reports a timeout as "бинарь не завершился" and passes a 30 s timeout to every launch', () => {
        writeSnapshot();
        const exec = jest.fn(() => {
            throw Object.assign(new Error('spawnSync ETIMEDOUT'), { code: 'ETIMEDOUT' });
        });

        const result = run(opts({ exec }));

        expect(result.ok).toBe(false);
        expect(result.diff.join('\n')).toMatch(/бинарь не завершился/);
        expect(LAUNCH_TIMEOUT_MS).toBe(30000);
        for (const call of exec.mock.calls) {
            expect(call[2]).toEqual({ timeoutMs: 30000 });
        }
    });

    test('launches qbittorrent-nox with --profile=<temporary directory>, removed afterwards', () => {
        writeSnapshot();
        const exec = okExec();

        run(opts({ exec }));

        const call = exec.mock.calls.find(([command]) => command.includes('qbittorrent-nox'));
        const profileArg = call[1].find((arg) => arg.startsWith('--profile='));
        expect(profileArg).toBeDefined();
        expect(call[1]).toContain('--version');
        expect(fs.existsSync(profileArg.slice('--profile='.length))).toBe(false);
        expect(profileArg.slice('--profile='.length)).not.toBe('');
    });

    test('--no-launch skips launching, without it every bin is launched', () => {
        writeSnapshot();

        const skipped = okExec();
        expect(run(opts({ exec: skipped, noLaunch: true })).ok).toBe(true);
        expect(skipped).not.toHaveBeenCalled();

        const launched = okExec();
        expect(run(opts({ exec: launched })).ok).toBe(true);
        expect(launched).toHaveBeenCalledTimes(3);
    });

    test('takes the fingerprint before launching: a launch cannot hide a changed file', () => {
        writeSnapshot();
        fs.writeFileSync(path.join(binDir, 'ffprobe', 'extra.dll'), 'x');
        const exec = okExec();

        const result = run(opts({ exec }));

        expect(result.ok).toBe(false);
        expect(exec).not.toHaveBeenCalled();
    });

    test('fails explicitly on non-Windows without --no-launch', () => {
        writeSnapshot();
        const exec = okExec();

        const result = run(opts({ exec, platform: 'linux' }));

        expect(result.ok).toBe(false);
        expect(result.message).toMatch(/только на Windows.*--no-launch/);
        expect(exec).not.toHaveBeenCalled();
    });

    test('--no-launch works on non-Windows', () => {
        writeSnapshot();

        expect(run(opts({ platform: 'linux', noLaunch: true })).ok).toBe(true);
    });

    test('a profile cleanup failure (EBUSY) does not change the result', () => {
        writeSnapshot();
        const realRmSync = fs.rmSync;
        const spy = jest.spyOn(fs, 'rmSync').mockImplementation((target, options) => {
            if (String(target).includes('qbt-profile-')) {
                throw Object.assign(new Error('EBUSY: resource busy or locked'), { code: 'EBUSY' });
            }
            return realRmSync(target, options);
        });
        try {
            const result = run(opts());

            expect(result).toMatchObject({ ok: true, exitCode: 0 });
            expect(spy).toHaveBeenCalledWith(expect.stringContaining('qbt-profile-'), expect.objectContaining({ maxRetries: 3 }));
        } finally {
            spy.mockRestore();
        }
    });

    test('parseArgs rejects an unknown argument listing the valid flags', () => {
        expect(() => parseArgs(['--wirte'])).toThrow(BundledBinsError);
        expect(() => parseArgs(['--wirte'])).toThrow(/--wirte.*--write, --no-launch, --bin-dir, --snapshot, --versions/);
    });

    test('parseArgs accepts all known flags', () => {
        expect(parseArgs(['--write', '--no-launch', '--bin-dir', 'a', '--snapshot', 'b', '--versions', 'c'])).toEqual({
            write: true,
            noLaunch: true,
            binDir: 'a',
            snapshotPath: 'b',
            versionsPath: 'c',
        });
    });
});

describe('committed bundled-bins snapshot', () => {
    const scriptsDir = path.resolve(__dirname, '../../scripts');
    const snapshot = JSON.parse(fs.readFileSync(path.join(scriptsDir, 'bundled-bins-runtime.json'), 'utf8'));
    const versions = JSON.parse(fs.readFileSync(path.join(scriptsDir, 'versions.json'), 'utf8'));

    test.each([
        ['meilisearch', 'meilisearch'],
        ['qbittorrent-nox', 'qbittorrentNox'],
        ['ffprobe', 'ffprobe'],
    ])('%s section version matches versions.json', (section, versionKey) => {
        expect(snapshot[section].version).toBe(versions[versionKey]);
    });

    test('meilisearch.exe fingerprint equals the pinned archive SHA-256', () => {
        expect(snapshot.meilisearch.directoryFingerprint['meilisearch.exe']).toBe(versions.sha256.meilisearch);
    });
});
