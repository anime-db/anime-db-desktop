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

const fs     = require('fs');
const path   = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { buildPhpCliEvalArgs } = require('../native/supervisor/php-cli-command');

const rootDir = path.resolve(__dirname, '..');

const DEFAULT_RUNTIME_DIR    = path.join(rootDir, 'bin', 'frankenphp');
const DEFAULT_SNAPSHOT_PATH  = path.join(__dirname, 'frankenphp-runtime.json');
const DEFAULT_VERSIONS_PATH  = path.join(__dirname, 'versions.json');

// The endonym set this repo actually ships a language switcher for (see native/i18n, tests/native/i18n.test.js).
const LOCALES = ['en', 'ru', 'de', 'ja'];

// Curated, not exhaustive: only the ini directives that this app's own code branches on or that
// change user-visible formatting (date.timezone drives every displayed date; the others affect
// how PHP renders/reads strings and floats). Not a dump of the whole ini — see the docblock on
// `frankenphp-runtime.json`'s "ini" field for the same note kept next to the recorded values.
const INI_KEYS = ['date.timezone', 'default_charset', 'mbstring.internal_encoding', 'precision', 'serialize_precision'];

// Run via `<binary> php-cli -r '<code>'` — the only invocation form the packaged FrankenPHP
// binary's `php-cli` subcommand actually parses, see native/supervisor/php-cli-command.js's
// docblock and .claude-docs/gotchas.md. `$locales`/`$iniKeys` are JSON arrays of string literals,
// which is also valid PHP array-literal syntax, so interpolating LOCALES/INI_KEYS here keeps this
// snippet and the JS constants above as the single source of truth for what gets collected.
const FACTS_CODE = `
$locales = ${JSON.stringify(LOCALES)};
$iniKeys = ${JSON.stringify(INI_KEYS)};

$endonyms = [];
foreach ($locales as $locale) {
    $endonyms[$locale] = class_exists('Locale') ? \\Locale::getDisplayName($locale, $locale) : null;
}

$cldrVersion = null;
if (class_exists('ResourceBundle')) {
    $bundle = \\ResourceBundle::create('root', 'ICUDATA', false);
    $cldrVersion = $bundle !== null ? $bundle->get('Version') : null;
}

$ini = [];
foreach ($iniKeys as $key) {
    $ini[$key] = ini_get($key);
}

echo json_encode([
    'extensions'     => get_loaded_extensions(),
    'icuVersion'     => defined('INTL_ICU_VERSION') ? \\INTL_ICU_VERSION : null,
    'cldrVersion'    => $cldrVersion,
    'localeEndonyms' => $endonyms,
    'ini'            => $ini,
]);
`;

/** Thrown for conditions the caller should be told about in plain language, never as a stack trace. */
class RuntimeUnavailableError extends Error {}

/**
 * Lists every regular file under `dir`, recursively, skipping dotfiles — in particular
 * `.version`, the marker `scripts/download-bins.js` writes next to an extracted bin. That marker
 * is bookkeeping about the download, not a file FrankenPHP ships or loads, and its content is the
 * same version string already tracked in `frankenphpVersion`; folding it into the fingerprint too
 * would just make every version bump report two diffs for one change.
 *
 * @returns {{ abs: string, rel: string }[]} sorted by `rel` (POSIX-style, `/`-joined) for a stable fingerprint
 */
function listRuntimeFiles(dir) {
    const results = [];

    const walk = (currentDir, relPrefix) => {
        for (const entry of fs.readdirSync(currentDir, { withFileTypes: true })) {
            if (entry.name.startsWith('.')) continue;

            const abs = path.join(currentDir, entry.name);
            const rel = relPrefix ? `${relPrefix}/${entry.name}` : entry.name;

            if (entry.isDirectory()) {
                walk(abs, rel);
            } else if (entry.isFile()) {
                results.push({ abs, rel });
            }
        }
    };

    walk(dir, '');

    return results.sort((a, b) => a.rel.localeCompare(b.rel));
}

/**
 * Fingerprints the whole runtime directory as a `path -> sha256` map, not a single hash of one
 * binary: after #477 `bin/frankenphp/` is a multi-file bundle (`frankenphp.exe`, `php8ts.dll`,
 * `ext/php_*.dll`, ICU libraries — see scripts/download-bins.js's `FRANKENPHP_FILES`), and a
 * missing or extra file there is exactly the failure this snapshot exists to catch. Hashing only
 * `frankenphp.exe` would miss a build that shipped without `ext/php_intl.dll`, for example.
 *
 * @returns {Record<string, string>}
 */
function fingerprintDirectory(dir) {
    const fingerprint = {};

    for (const { abs, rel } of listRuntimeFiles(dir)) {
        fingerprint[rel] = crypto.createHash('sha256').update(fs.readFileSync(abs)).digest('hex');
    }

    return fingerprint;
}

/**
 * @returns {string|null} absolute path to the FrankenPHP binary inside `runtimeDir`, or `null`
 *                         when the directory doesn't exist, is empty, or has no such binary —
 *                         all three read as "runtime not downloaded yet" to the caller.
 */
function findRuntimeBinary(runtimeDir) {
    if (!fs.existsSync(runtimeDir)) return null;

    const match = fs.readdirSync(runtimeDir).find((name) => /^frankenphp(\.exe)?$/i.test(name));

    return match ? path.join(runtimeDir, match) : null;
}

/**
 * @param {string} runtimeDir
 * @param {(command: string, args: string[]) => string} exec
 * @returns {{ directoryFingerprint: Record<string, string>, extensions: string[], icu: { version: string|null, cldrVersion: string|null }, localeEndonyms: Record<string, string>, ini: Record<string, string|false|null> }}
 * @throws {RuntimeUnavailableError} when the runtime isn't on disk, or the binary can't be run
 */
function collectRuntimeFacts(runtimeDir, exec) {
    const binaryPath = findRuntimeBinary(runtimeDir);
    if (binaryPath === null) {
        throw new RuntimeUnavailableError(
            `Рантайм FrankenPHP не найден в ${runtimeDir}. Сначала запусти:\n  npm run download-bins`,
        );
    }

    const [command, ...args] = buildPhpCliEvalArgs(binaryPath, FACTS_CODE);

    let raw;
    try {
        raw = exec(command, args);
    } catch (err) {
        throw new RuntimeUnavailableError(
            `Не удалось выполнить "${binaryPath} php-cli -r '<code>'": ${err.message}`,
        );
    }

    const parsed = JSON.parse(raw);

    return {
        directoryFingerprint: fingerprintDirectory(runtimeDir),
        extensions:           [...parsed.extensions].sort(),
        icu:                  { version: parsed.icuVersion ?? null, cldrVersion: parsed.cldrVersion ?? null },
        localeEndonyms:       parsed.localeEndonyms ?? {},
        ini:                  parsed.ini ?? {},
    };
}

const SNAPSHOT_COMMENT =
    'Fingerprint of the DEPLOYED FrankenPHP runtime directory (bin/frankenphp/) as it exists ' +
    'after extraction on the build machine — every shipped file gets its own SHA-256 under ' +
    '"directoryFingerprint". This is a different artifact from scripts/versions.json\'s "sha256", ' +
    'which is the SHA-256 of the downloaded release archive BEFORE extraction; that one proves ' +
    'the archive was not tampered with in transit, this one proves the extracted runtime still ' +
    'matches what was captured. Write this file by running the target FrankenPHP build (Windows) ' +
    'through `node scripts/check-runtime-parity.js --write`; never by hand and never from a Linux ' +
    'checkout — the Linux build differs in extension set and ICU version, see issue #471.';

/**
 * @returns {object} the full snapshot shape, ready to JSON.stringify and write to `snapshotPath`
 */
function buildSnapshot(runtimeDir, versionsPath, exec) {
    const versions = JSON.parse(fs.readFileSync(versionsPath, 'utf8'));

    return {
        $comment:          SNAPSHOT_COMMENT,
        $stub:             false,
        frankenphpVersion: versions.frankenphp,
        ...collectRuntimeFacts(runtimeDir, exec),
    };
}

/** @returns {object|null} `null` when `snapshotPath` doesn't exist */
function readSnapshot(snapshotPath) {
    if (!fs.existsSync(snapshotPath)) return null;

    return JSON.parse(fs.readFileSync(snapshotPath, 'utf8'));
}

function writeSnapshot(snapshotPath, snapshot) {
    fs.writeFileSync(snapshotPath, JSON.stringify(snapshot, null, 4) + '\n', 'utf8');
}

function diffValue(label, expected, actual) {
    if (JSON.stringify(expected) === JSON.stringify(actual)) return [];

    return [`${label}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`];
}

/** Order-insensitive: compares `expected`/`actual` as sets, since extension load order isn't meaningful. */
function diffSet(label, expected = [], actual = []) {
    const expectedSet = new Set(expected);
    const actualSet = new Set(actual);
    const lines = [];

    for (const value of [...expectedSet].sort()) {
        if (!actualSet.has(value)) lines.push(`${label}: missing "${value}"`);
    }
    for (const value of [...actualSet].sort()) {
        if (!expectedSet.has(value)) lines.push(`${label}: unexpected "${value}"`);
    }

    return lines;
}

function diffMap(label, expected = {}, actual = {}) {
    const keys = new Set([...Object.keys(expected), ...Object.keys(actual)]);
    const lines = [];

    for (const key of [...keys].sort()) {
        const hasExpected = Object.prototype.hasOwnProperty.call(expected, key);
        const hasActual = Object.prototype.hasOwnProperty.call(actual, key);
        if (hasExpected && hasActual && expected[key] === actual[key]) continue;

        const expectedLabel = hasExpected ? JSON.stringify(expected[key]) : '(missing)';
        const actualLabel = hasActual ? JSON.stringify(actual[key]) : '(missing)';
        lines.push(`${label}["${key}"]: expected ${expectedLabel}, got ${actualLabel}`);
    }

    return lines;
}

/** @returns {string[]} human-readable diff lines, empty when `expected` and `actual` agree on everything checked */
function diffFacts(expected, actual) {
    return [
        ...diffValue('frankenphpVersion', expected.frankenphpVersion, actual.frankenphpVersion),
        ...diffMap('directoryFingerprint', expected.directoryFingerprint, actual.directoryFingerprint),
        ...diffSet('extensions', expected.extensions, actual.extensions),
        ...diffValue('icu.version', expected.icu && expected.icu.version, actual.icu && actual.icu.version),
        ...diffValue('icu.cldrVersion', expected.icu && expected.icu.cldrVersion, actual.icu && actual.icu.cldrVersion),
        ...diffMap('localeEndonyms', expected.localeEndonyms, actual.localeEndonyms),
        ...diffMap('ini', expected.ini, actual.ini),
    ];
}

function defaultExec(command, args) {
    return execFileSync(command, args, { encoding: 'utf8' });
}

/**
 * Renders a minimal `--flag [value]` argv into an options object, same shape as
 * scripts/generate-php-ini.js's `parseArgs()`.
 *
 * @returns {{ write?: boolean, runtimeDir?: string, snapshotPath?: string, versionsPath?: string }}
 */
function parseArgs(argv) {
    const options = {};
    for (let i = 0; i < argv.length; i++) {
        switch (argv[i]) {
            case '--write':
                options.write = true;
                break;
            case '--runtime-dir':
                options.runtimeDir = argv[++i];
                break;
            case '--snapshot':
                options.snapshotPath = argv[++i];
                break;
            case '--versions':
                options.versionsPath = argv[++i];
                break;
        }
    }
    return options;
}

/**
 * The whole write-or-compare flow as a pure function: never touches `console`/`process.exit`
 * itself, so tests can assert on the returned result directly instead of intercepting output.
 *
 * @returns {{ ok: boolean, exitCode: number, message: string, diff: string[] }}
 */
function run({
    runtimeDir = DEFAULT_RUNTIME_DIR,
    snapshotPath = DEFAULT_SNAPSHOT_PATH,
    versionsPath = DEFAULT_VERSIONS_PATH,
    write = false,
    exec = defaultExec,
} = {}) {
    let facts;
    try {
        facts = buildSnapshot(runtimeDir, versionsPath, exec);
    } catch (err) {
        if (err instanceof RuntimeUnavailableError) {
            return { ok: false, exitCode: 1, message: err.message, diff: [] };
        }
        throw err;
    }

    if (write) {
        writeSnapshot(snapshotPath, facts);
        return { ok: true, exitCode: 0, message: `Снимок рантайма записан в ${snapshotPath}`, diff: [] };
    }

    const snapshot = readSnapshot(snapshotPath);
    if (snapshot === null) {
        return {
            ok: false,
            exitCode: 1,
            message: `Слепок ${snapshotPath} не найден. Сначала запиши его на боевом рантайме:\n  node scripts/check-runtime-parity.js --write`,
            diff: [],
        };
    }
    if (snapshot.$stub) {
        return {
            ok: false,
            exitCode: 1,
            message: `Слепок ${snapshotPath} — незаполненная заглушка (см. поле "$comment" в файле). Запиши его на боевом рантайме:\n  node scripts/check-runtime-parity.js --write`,
            diff: [],
        };
    }

    const diff = diffFacts(snapshot, facts);
    if (diff.length === 0) {
        return { ok: true, exitCode: 0, message: 'Рантайм соответствует слепку.', diff: [] };
    }
    return { ok: false, exitCode: 1, message: 'Рантайм разошёлся со слепком:', diff };
}

function main() {
    const result = run(parseArgs(process.argv.slice(2)));

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
    buildSnapshot,
    collectRuntimeFacts,
    fingerprintDirectory,
    findRuntimeBinary,
    diffFacts,
    readSnapshot,
    writeSnapshot,
    RuntimeUnavailableError,
    FACTS_CODE,
    LOCALES,
    INI_KEYS,
};
