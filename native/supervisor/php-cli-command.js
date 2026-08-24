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

const path = require('path');

/**
 * JS-side mirror of {@see \App\Service\Plugin\PhpCliCommand} (PHP side) — the single place this
 * repo's Node code (the supervisor and any build/maintenance script) encodes the rule that
 * FrankenPHP's packaged binary requires a `php-cli` subcommand to run the embedded PHP runtime
 * as a CLI interpreter, unlike a plain PHP binary. Calling `frankenphp(.exe)` directly with
 * interpreter-style arguments does not work — that gap is what issue #410 traced.
 *
 * The `php-cli` subcommand is NOT a full CLI SAPI wrapper: FrankenPHP sets
 * `cmd.DisableFlagParsing = true` and forwards everything verbatim, branching only on
 * `args[0] === '-r'` (run inline code); anything else is treated as a path to a script to
 * require. So interpreter flags — `-l`, `-m`, `-v` — are read as filenames and fail with
 * `Failed opening required '-l'` and exit code 255, indistinguishable from a real failure. See
 * `.claude-docs/gotchas.md` and issue #478 for the breakage this caused.
 *
 * That is why this module exposes only {@see buildPhpCliScriptArgs} and
 * {@see buildPhpCliEvalArgs} rather than an arguments-passthrough builder: neither can produce a
 * flag-based invocation, so a caller cannot accidentally rebuild the broken form.
 *
 * @param {string} binaryPath
 * @param {...string} args
 * @returns {[string, ...string[]]} `[command, ...args]`, ready to spread into `spawn`/`execFileSync`
 */
function build(binaryPath, ...args) {
    if (/^frankenphp(\.exe)?$/i.test(path.basename(binaryPath))) {
        return [binaryPath, 'php-cli', ...args];
    }

    return [binaryPath, ...args];
}

/**
 * For running a PHP script file, e.g. `bin/console`, with its own arguments.
 *
 * @param {string} binaryPath
 * @param {string} scriptPath
 * @param {...string} scriptArguments
 * @returns {[string, ...string[]]}
 */
function buildPhpCliScriptArgs(binaryPath, scriptPath, ...scriptArguments) {
    return build(binaryPath, scriptPath, ...scriptArguments);
}

/**
 * For running a snippet of PHP source directly, via `-r`.
 *
 * @param {string} binaryPath
 * @param {string} code
 * @returns {[string, ...string[]]}
 */
function buildPhpCliEvalArgs(binaryPath, code) {
    return build(binaryPath, '-r', code);
}

module.exports = { buildPhpCliScriptArgs, buildPhpCliEvalArgs };
