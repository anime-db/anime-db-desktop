<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service\Download;

/**
 * Abstracts PHP's built-in `disk_free_space()` behind an interface, the same shape as
 * {@see \App\Service\JobLock\ProcessLivenessChecker} for the same reason: a built-in filesystem
 * function cannot be mocked directly, so {@see FreeSpaceChecker} depends on this instead and is
 * unit-testable without touching a real volume.
 */
interface FreeSpaceProvider
{
    /**
     * Returns the number of free bytes on the volume containing $path, or null if it could not
     * be determined (e.g. $path does not exist yet).
     */
    public function getFreeBytes(string $path): ?int;
}
