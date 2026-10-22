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
const { execFileSync } = require('child_process');
const { fingerprintDirectory, diffMap } = require('./check-runtime-parity');

const rootDir = path.resolve(__dirname, '..');

const DEFAULT_BIN_DIR       = path.join(rootDir, 'bin');
const DEFAULT_SNAPSHOT_PATH = path.join(__dirname, 'bundled-bins-runtime.json');
const DEFAULT_VERSIONS_PATH = path.join(__dirname, 'versions.json');

const LAUNCH_TIMEOUT_MS = 30000;

// name -> key in versions.json. The snapshot sections are keyed by the bin's directory name.
const BINS = [
    { name: 'meilisearch', versionKey: 'meilisearch', exe: 'meilisearch.exe' },
    { name: 'qbittorrent-nox', versionKey: 'qbittorrentNox', exe: 'qbittorrent-nox.exe' },
    { name: 'ffprobe', versionKey: 'ffprobe', exe: 'ffprobe.exe' },
];

const SNAPSHOT_COMMENT =
    'Fingerprint of the extracted directories bin/meilisearch/, bin/qbittorrent-nox/ and bin/ffprobe/ ' +
    '(path -> SHA-256 of every shipped file, dotfiles skipped) for the versions pinned in ' +
    'scripts/versions.json. Captured from the archives by `node scripts/check-bundled-bins.js --write` ' +
    '(runs no binary, works on any OS) after `node scripts/download-bins.js --only ' +
    'meilisearch,qbittorrent-nox,ffprobe`. Rewrite it by hand-run `--write` in the same change that ' +
    'bumps a version pin; the check never rewrites it.';

/** Thrown for conditions the caller should be told about in plain language, never as a stack trace. */
class BundledBinsError extends Error {}

function upstreamVersion(version) {
    return version.split('_')[0];
}

/**
 * Runs `command` and returns its stdout. Throws on a non-zero exit code or when the process does
 * not finish within `timeoutMs`.
 */
function defaultExec(command, args, { timeoutMs = LAUNCH_TIMEOUT_MS } = {}) {
    return execFileSync(command, args, {
        encoding: 'utf8',
        timeout: timeoutMs,
        stdio: ['ignore', 'pipe', 'pipe'],
        windowsHide: true,
    });
}

function launchArgs(bin, profileDir) {
    switch (bin.name) {
        case 'ffprobe':
            return ['-version'];
        case 'qbittorrent-nox':
            // Without --profile qBittorrent would migrate the real user profile (%APPDATA%\qBittorrent).
            return [`--profile=${profileDir}`, '--version'];
        default:
            return ['--version'];
    }
}

/** Best effort: on Windows the profile may stay locked (EBUSY/EPERM), which must not fail the check. */
function removeProfileDir(profileDir) {
    try {
        fs.rmSync(profileDir, { recursive: true, force: true, maxRetries: 3 });
    } catch {
        // a leftover temp directory is harmless
    }
}

/**
 * Launches the bin with a version flag and applies the rule: exit code 0, first output line
 * non-empty and containing the upstream version. Returns an error line, or null when it passes.
 */
function checkLaunch(bin, version, binDir, exec) {
    const exePath = path.join(binDir, bin.name, bin.exe);
    const profileDir = bin.name === 'qbittorrent-nox'
        ? fs.mkdtempSync(path.join(os.tmpdir(), 'qbt-profile-'))
        : null;

    let output;
    try {
        output = exec(exePath, launchArgs(bin, profileDir), { timeoutMs: LAUNCH_TIMEOUT_MS });
    } catch (err) {
        if (err.code === 'ETIMEDOUT') {
            return `${bin.name}: бинарь не завершился за ${LAUNCH_TIMEOUT_MS / 1000} с`;
        }
        return `${bin.name}: запуск не удался: ${err.message}`;
    } finally {
        if (profileDir !== null) removeProfileDir(profileDir);
    }

    const firstLine = String(output ?? '').split(/\r?\n/)[0].trim();
    if (firstLine === '') {
        return `${bin.name}: первая строка вывода пустая`;
    }
    const expected = upstreamVersion(version);
    if (!firstLine.includes(expected)) {
        return `${bin.name}: первая строка вывода ${JSON.stringify(firstLine)} не содержит версию ${expected}`;
    }

    return null;
}

const KNOWN_FLAGS = ['--write', '--no-launch', '--bin-dir', '--snapshot', '--versions'];

function parseArgs(argv) {
    const options = {};
    for (let i = 0; i < argv.length; i++) {
        switch (argv[i]) {
            case '--write':
                options.write = true;
                break;
            case '--no-launch':
                options.noLaunch = true;
                break;
            case '--bin-dir':
                options.binDir = argv[++i];
                break;
            case '--snapshot':
                options.snapshotPath = argv[++i];
                break;
            case '--versions':
                options.versionsPath = argv[++i];
                break;
            default:
                throw new BundledBinsError(
                    `Неизвестный аргумент: ${argv[i]}. Допустимые флаги: ${KNOWN_FLAGS.join(', ')}`,
                );
        }
    }
    return options;
}

function collectDirectories(binDir) {
    const sections = {};
    for (const bin of BINS) {
        const dir = path.join(binDir, bin.name);
        if (!fs.existsSync(dir)) {
            throw new BundledBinsError(
                `Каталог ${dir} не найден. Сначала запусти:\n  node scripts/download-bins.js --only ${BINS.map((b) => b.name).join(',')}`,
            );
        }
        sections[bin.name] = fingerprintDirectory(dir);
    }
    return sections;
}

/**
 * The whole write-or-compare flow as a pure function: never touches `console`/`process.exit`.
 * `exec(command, args, { timeoutMs })` and `platform` are injectable for tests.
 *
 * @returns {{ ok: boolean, exitCode: number, message: string, diff: string[] }}
 */
function run({
    binDir = DEFAULT_BIN_DIR,
    snapshotPath = DEFAULT_SNAPSHOT_PATH,
    versionsPath = DEFAULT_VERSIONS_PATH,
    write = false,
    noLaunch = false,
    exec = defaultExec,
    platform = process.platform,
} = {}) {
    const fail = (message, diff = []) => ({ ok: false, exitCode: 1, message, diff });

    if (!write && !noLaunch && platform !== 'win32') {
        return fail('Запуск возможен только на Windows, для сверки состава используй `--no-launch`.');
    }

    const versions = JSON.parse(fs.readFileSync(versionsPath, 'utf8'));

    // The fingerprint is taken before any binary is launched.
    let directories;
    try {
        directories = collectDirectories(binDir);
    } catch (err) {
        if (err instanceof BundledBinsError) return fail(err.message);
        throw err;
    }

    if (write) {
        const snapshot = { $comment: SNAPSHOT_COMMENT };
        for (const bin of BINS) {
            snapshot[bin.name] = {
                version: versions[bin.versionKey],
                directoryFingerprint: directories[bin.name],
            };
        }
        fs.writeFileSync(snapshotPath, JSON.stringify(snapshot, null, 4) + '\n', 'utf8');
        return { ok: true, exitCode: 0, message: `Слепок поставки записан в ${snapshotPath}`, diff: [] };
    }

    if (!fs.existsSync(snapshotPath)) {
        return fail(
            `Слепок ${snapshotPath} не найден. Запиши слепок:\n  node scripts/check-bundled-bins.js --write`,
        );
    }
    const snapshot = JSON.parse(fs.readFileSync(snapshotPath, 'utf8'));

    const diff = [];
    for (const bin of BINS) {
        const section = snapshot[bin.name];
        if (!section) {
            diff.push(`${bin.name}: в слепке нет секции, запиши слепок`);
            continue;
        }
        const pinned = versions[bin.versionKey];
        if (section.version !== pinned) {
            diff.push(`${bin.name}.version: expected ${JSON.stringify(section.version)}, got ${JSON.stringify(pinned)} (versions.json)`);
        }
        diff.push(...diffMap(`${bin.name}.directoryFingerprint`, section.directoryFingerprint, directories[bin.name]));
    }
    if (diff.length > 0) {
        return fail('Поставка разошлась со слепком:', diff);
    }

    if (!noLaunch) {
        const launchDiff = [];
        for (const bin of BINS) {
            const error = checkLaunch(bin, versions[bin.versionKey], binDir, exec);
            if (error !== null) launchDiff.push(error);
        }
        if (launchDiff.length > 0) {
            return fail('Проверка запуска не пройдена:', launchDiff);
        }
    }

    return {
        ok: true,
        exitCode: 0,
        message: noLaunch ? 'Состав поставки соответствует слепку.' : 'Состав поставки соответствует слепку, бинари запускаются.',
        diff: [],
    };
}

function main() {
    let options;
    try {
        options = parseArgs(process.argv.slice(2));
    } catch (err) {
        if (err instanceof BundledBinsError) {
            console.error(err.message);
            process.exit(1);
        }
        throw err;
    }
    const result = run(options);

    (result.ok ? console.log : console.error)(result.message);
    for (const line of result.diff) {
        console.error(`  ${line}`);
    }

    process.exit(result.exitCode);
}

if (require.main === module) {
    main();
}

module.exports = {
    run,
    parseArgs,
    BundledBinsError,
    BINS,
    LAUNCH_TIMEOUT_MS,
};
