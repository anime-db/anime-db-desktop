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

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { buildPhpCliArgs } = require('../native/supervisor/php-cli-command');

const REPO_ROOT = path.join(__dirname, '..');
const BINARY_PATH = path.join(REPO_ROOT, 'bin', 'frankenphp', 'frankenphp.exe');
const VERSIONS_PATH = path.join(__dirname, 'versions.json');
const SNAPSHOT_PATH = path.join(__dirname, 'frankenphp-runtime.json');

// The locales the language switcher actually offers (issue #461) — a mix of core (en, ru) and
// plugin-contributed (de, ja) locales, chosen so the snapshot exercises both origins.
const LOCALES = ['en', 'ru', 'de', 'ja'];

// ini defaults that are not set by bin/php/php.ini.template (so they come from FrankenPHP's own
// build, not from app config) and that app code actually depends on:
//  - short_open_tag: FrankenPHP's static build ships it On, unlike a typical php.ini-production
//    (Off) — a divergence worth catching even though the app does not currently rely on it.
//  - zend.assertions: this build ships it enabled (1), so `\assert()` calls (see
//    App\Service\Plugin\PluginDataStore) actually execute instead of being compiled out (-1), as
//    a hardened production php.ini would normally set.
const INI_KEYS = ['short_open_tag', 'zend.assertions'];

/**
 * Runs `<binary> php-cli -r <phpCode>` and returns its stdout — the only invocation shapes
 * FrankenPHP's `php-cli` subcommand actually supports are "run this script file" and "run this
 * inline code" (see `caddy/php-cli.go` in the FrankenPHP source); CLI-SAPI-style flags like `-m`
 * or `-l` are not recognized and are instead treated as a script path to require, which fails.
 * Every fact below is therefore gathered by asking PHP itself, via `-r`, rather than by an
 * unsupported flag.
 */
function runPhpCli(binaryPath, phpCode) {
    const [command, ...args] = buildPhpCliArgs(binaryPath, '-r', phpCode);

    return execFileSync(command, args, { encoding: 'utf8' });
}

function collectExtensions(binaryPath) {
    const output = runPhpCli(binaryPath, 'echo implode("\\n", get_loaded_extensions());');

    return output.split('\n').map((line) => line.trim()).filter(Boolean).sort();
}

function collectIcu(binaryPath) {
    const output = runPhpCli(binaryPath, 'echo INTL_ICU_VERSION, "|", INTL_ICU_DATA_VERSION;');
    const [version, cldrVersion] = output.trim().split('|');

    return { version, cldrVersion };
}

function collectLocaleEndonyms(binaryPath) {
    const code = `
        $out = [];
        foreach (${JSON.stringify(LOCALES)} as $locale) {
            $name = \\Locale::getDisplayName($locale, $locale);
            $out[$locale] = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        }
        echo json_encode($out);
    `;

    return JSON.parse(runPhpCli(binaryPath, code).trim());
}

function collectIni(binaryPath) {
    const code = `
        $out = [];
        foreach (${JSON.stringify(INI_KEYS)} as $key) {
            $out[$key] = ini_get($key);
        }
        echo json_encode($out);
    `;

    return JSON.parse(runPhpCli(binaryPath, code).trim());
}

/**
 * Snapshots the facts this repo tracks about a FrankenPHP binary: its pinned version (from
 * `versions.json`, not queried from the binary itself — that is the source of truth for what
 * *should* be installed), the SHA-256 of the binary actually on disk, its loaded extensions, its
 * statically-linked ICU/CLDR version, the endonyms it resolves for the app's locales, and a
 * handful of ini defaults the app depends on.
 *
 * @throws {Error} when the binary is missing, with a message pointing at how to fetch it
 */
function collectFacts(binaryPath) {
    if (!fs.existsSync(binaryPath)) {
        throw new Error(
            `FrankenPHP binary not found at ${binaryPath}.\n` +
            'Run `npm run download-bins` to download the pinned build first.',
        );
    }

    const { frankenphp: version } = JSON.parse(fs.readFileSync(VERSIONS_PATH, 'utf8'));
    // Hashes the extracted `frankenphp.exe` on disk — distinct from `versions.json`'s
    // `sha256.frankenphp`, which hashes the release *zip* the exe was extracted from. Keep the
    // two named apart: reconciling them into one field would hide that they hash different
    // artifacts and can legitimately disagree (e.g. after a zip re-extraction).
    const exeSha256 = crypto.createHash('sha256').update(fs.readFileSync(binaryPath)).digest('hex');

    return {
        frankenphp: { version, exeSha256 },
        extensions: collectExtensions(binaryPath),
        icu: collectIcu(binaryPath),
        localeEndonyms: collectLocaleEndonyms(binaryPath),
        ini: collectIni(binaryPath),
    };
}

function diffScalar(lines, keyPath, expected, actual) {
    if (expected !== actual) {
        lines.push(`${keyPath}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
    }
}

function diffStringList(lines, keyPath, expected, actual) {
    const expectedSet = new Set(expected);
    const actualSet = new Set(actual);
    const missing = expected.filter((item) => !actualSet.has(item));
    const unexpected = actual.filter((item) => !expectedSet.has(item));

    if (missing.length > 0) {
        lines.push(`${keyPath}: missing ${JSON.stringify(missing)}`);
    }
    if (unexpected.length > 0) {
        lines.push(`${keyPath}: unexpected ${JSON.stringify(unexpected)}`);
    }
}

function diffStringMap(lines, keyPath, expected, actual) {
    const keys = new Set([...Object.keys(expected), ...Object.keys(actual)]);

    [...keys].sort().forEach((key) => diffScalar(lines, `${keyPath}.${key}`, expected[key], actual[key]));
}

/**
 * @returns {string[]} one line per divergence, empty when `actual` matches `expected`
 */
function diffFacts(expected, actual) {
    const lines = [];

    diffScalar(lines, 'frankenphp.version', expected.frankenphp.version, actual.frankenphp.version);
    diffScalar(lines, 'frankenphp.exeSha256', expected.frankenphp.exeSha256, actual.frankenphp.exeSha256);
    diffStringList(lines, 'extensions', expected.extensions, actual.extensions);
    diffScalar(lines, 'icu.version', expected.icu.version, actual.icu.version);
    diffScalar(lines, 'icu.cldrVersion', expected.icu.cldrVersion, actual.icu.cldrVersion);
    diffStringMap(lines, 'localeEndonyms', expected.localeEndonyms, actual.localeEndonyms);
    diffStringMap(lines, 'ini', expected.ini, actual.ini);

    return lines;
}

function writeSnapshot(facts) {
    fs.writeFileSync(SNAPSHOT_PATH, `${JSON.stringify(facts, null, 4)}\n`);
}

function readSnapshot() {
    return JSON.parse(fs.readFileSync(SNAPSHOT_PATH, 'utf8'));
}

function main(argv) {
    let facts;
    try {
        facts = collectFacts(BINARY_PATH);
    } catch (err) {
        console.error(err.message);
        process.exitCode = 1;
        return;
    }

    if (argv.includes('--write')) {
        writeSnapshot(facts);
        console.log(`Snapshot written to ${path.relative(REPO_ROOT, SNAPSHOT_PATH)}`);
        return;
    }

    const diff = diffFacts(readSnapshot(), facts);
    if (diff.length === 0) {
        console.log('FrankenPHP runtime matches the recorded snapshot.');
        return;
    }

    console.error('FrankenPHP runtime differs from the recorded snapshot:');
    diff.forEach((line) => console.error(`  ${line}`));
    process.exitCode = 1;
}

if (require.main === module) {
    main(process.argv.slice(2));
}

module.exports = {
    BINARY_PATH,
    SNAPSHOT_PATH,
    collectFacts,
    diffFacts,
};
