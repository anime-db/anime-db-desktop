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

namespace App\Service\Download;

/**
 * The only production {@see FreeSpaceProvider}: a thin wrapper around `disk_free_space()`. The
 * "@" suppresses the PHP warning `disk_free_space()` raises for a path that does not exist yet
 * (e.g. the downloads root before it has ever been created) — that case is reported as "unknown"
 * (null) rather than a fatal, exactly like the "not a Windows path" case below.
 */
final class NativeFreeSpaceProvider implements FreeSpaceProvider
{
    public function getFreeBytes(string $path): ?int
    {
        $free = @disk_free_space($path);

        return $free === false ? null : (int) $free;
    }
}
