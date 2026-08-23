<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */

/*
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

declare(strict_types=1);

namespace App\Service\Plugin;

/**
 * Builds a CLI invocation of the PHP interpreter behind `\PHP_BINARY`, for any caller that needs
 * to spawn a one-off PHP process (the isolated cache warm-up in {@see PluginCacheWarmer}, the
 * per-file syntax check in {@see ZipPluginInstaller}) rather than relying on it being invocable
 * directly.
 *
 * FrankenPHP's packaged binary embeds the PHP runtime itself and doubles as the CLI interpreter
 * via a `php-cli` subcommand — there is no separate `php.exe` shipped alongside it (see
 * `native/supervisor/php-command.js`). In that case `\PHP_BINARY` resolves to `frankenphp.exe`
 * itself, not to a plain PHP interpreter, so invoking it as one requires that subcommand first.
 * A regular PHP CLI binary (dev/CI) has no such requirement.
 *
 * `php-cli` does **not** parse PHP's own CLI flags (verified against the real FrankenPHP v1.12.4
 * Linux binary; the Windows build ships the same `php-cli` subcommand implementation but has not
 * been separately verified here): its first argument is always treated as a script path, with a
 * single hardcoded exception for `-r <code>`. Anything else — `-l`, `-v`, `-m`, `-d`, ... — is
 * opened as if it were a file named e.g. `-l`, fails with "Failed opening required '-l'", and
 * exits 255 regardless of what follows. That is why this class exposes only {@see self::forScript()}
 * and {@see self::forEval()} rather than an arguments-passthrough `build()`: neither can produce a
 * flag-based invocation, so a caller cannot accidentally rebuild the broken form.
 *
 * `-r`'s evaluated code also cannot rely on `$argv` for anything beyond argument 0: FrankenPHP's
 * `php-cli -r <code> <trailing args>` leaves `$argv` undefined (a regular PHP CLI binary populates
 * it as usual), so {@see self::forEval()} deliberately does not accept trailing script arguments —
 * pass data to the evaluated code through the child process's environment instead (e.g. via
 * `Process`'s `$env` argument and `getenv()`), the way {@see ZipPluginInstaller::assertNoSyntaxErrors()}
 * does.
 */
final class PhpCliCommand
{
    /**
     * For running a PHP script file, e.g. `bin/console`, with its own arguments.
     *
     * @return non-empty-list<string>
     */
    public static function forScript(string $phpBinary, string $scriptPath, string ...$scriptArguments): array
    {
        return self::build($phpBinary, $scriptPath, ...$scriptArguments);
    }

    /**
     * For running a snippet of PHP source directly, via `-r`. `$code` must not depend on `$argv`
     * — see the class docblock.
     *
     * @return non-empty-list<string>
     */
    public static function forEval(string $phpBinary, string $code): array
    {
        return self::build($phpBinary, '-r', $code);
    }

    /**
     * @return non-empty-list<string>
     */
    private static function build(string $phpBinary, string ...$arguments): array
    {
        if (preg_match('/^frankenphp(\.exe)?$/i', basename($phpBinary)) === 1) {
            return array_values([$phpBinary, 'php-cli', ...$arguments]);
        }

        return array_values([$phpBinary, ...$arguments]);
    }
}
