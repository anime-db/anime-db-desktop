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

namespace App\Service\Plugin\Exception;

use App\Service\Plugin\PluginSyntaxError;

/**
 * Thrown by {@see \App\Service\Plugin\ZipPluginInstaller::install()} when `php -l` finds a syntax
 * error in one or more of the unpacked plugin's `*.php` files. A blocking error: the install is
 * aborted before the unpacked directory is moved into `%app.plugins_dir%`, so a plugin that would
 * fatal on include never reaches the registry. Only applies to the custom (untrusted ZIP upload)
 * install path — marketplace plugins are linted on the registry side (issue #220).
 */
final class PluginSyntaxErrorException extends \RuntimeException
{
    /**
     * @param non-empty-list<PluginSyntaxError> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(\sprintf(
            "Plugin contains %d file(s) with PHP syntax errors:\n%s",
            \count($errors),
            implode("\n", array_map(
                static fn (PluginSyntaxError $error): string => \sprintf('  %s: %s', $error->relativePath, $error->message),
                $errors,
            )),
        ));
    }
}
