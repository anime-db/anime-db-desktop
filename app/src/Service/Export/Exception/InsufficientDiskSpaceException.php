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

namespace App\Service\Export\Exception;

/**
 * Thrown by {@see \App\Service\Export\CatalogExportService} when the destination volume does not
 * have enough free space for the estimated archive size (issue #657) — checked via
 * {@see \App\Service\Download\FreeSpaceProvider::getFreeBytes()} against the destination path
 * itself, not the downloads root {@see \App\Service\Download\FreeSpaceChecker} is pinned to.
 * Raised before anything is written to the destination directory, so a failed check never leaves
 * a partial file behind.
 */
final class InsufficientDiskSpaceException extends \RuntimeException
{
}
