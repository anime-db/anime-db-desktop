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
 * per-file syntax lint in {@see ZipPluginInstaller}) rather than relying on it being invocable
 * directly.
 *
 * FrankenPHP's packaged binary embeds the PHP runtime itself and doubles as the CLI interpreter
 * via a `php-cli` subcommand — there is no separate `php.exe` shipped alongside it (see
 * `native/supervisor/php-command.js`). In that case `\PHP_BINARY` resolves to `frankenphp.exe`
 * itself, not to a plain PHP interpreter, so invoking it as one requires that subcommand first.
 * A regular PHP CLI binary (dev/CI) has no such requirement.
 */
final class PhpCliCommand
{
    /**
     * @return non-empty-list<string>
     */
    public static function build(string $phpBinary, string ...$arguments): array
    {
        if (preg_match('/^frankenphp(\.exe)?$/i', basename($phpBinary)) === 1) {
            return array_values([$phpBinary, 'php-cli', ...$arguments]);
        }

        return array_values([$phpBinary, ...$arguments]);
    }
}
