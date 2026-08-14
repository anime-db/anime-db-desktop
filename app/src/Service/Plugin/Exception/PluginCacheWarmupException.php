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

/**
 * Thrown by {@see \App\Service\Plugin\PluginCacheWarmer::warmUp()} when the isolated child
 * process that compiles the DI container does not produce a usable result — a non-zero exit
 * code, a timeout, or a zero exit code without a compiled container actually being dumped (issue
 * #222). Deliberately fail-closed: a caller such as {@see \App\Service\Plugin\ZipPluginInstaller}
 * is expected to treat this the same as any other install failure and roll back, rather than
 * install a plugin whose kernel bootstrap could not be verified.
 */
final class PluginCacheWarmupException extends \RuntimeException
{
    public function __construct(string $processOutputTail, ?\Throwable $previous = null)
    {
        parent::__construct(
            \sprintf(
                "Plugin cache warm-up failed in an isolated process:\n%s",
                $processOutputTail !== '' ? $processOutputTail : '(no process output captured)',
            ),
            previous: $previous,
        );
    }
}
