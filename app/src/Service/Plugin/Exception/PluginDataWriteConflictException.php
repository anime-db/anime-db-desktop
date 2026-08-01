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

namespace App\Service\Plugin\Exception;

use App\Entity\ValueObject\PluginId;

/**
 * Thrown by {@see \App\Service\Plugin\PluginDataStore::write()} when every retry attempt still
 * lost the race to another concurrent writer. Deliberately a checked, catchable failure rather
 * than letting the underlying Doctrine exception propagate: a batch write loop (sync/scan/
 * download) is expected to catch this per item, log it, and move on to the next one rather than
 * aborting the whole run (issue #299) — a single anime's plugin data staying stale for one run is
 * far cheaper than losing every other item behind it in the same batch.
 */
final class PluginDataWriteConflictException extends \RuntimeException
{
    public function __construct(PluginId $pluginId, int $animeId, int $attempts, ?\Throwable $previous = null)
    {
        parent::__construct(
            \sprintf('Failed to write plugin "%s" data for anime #%d after %d attempt(s): another writer kept winning the race.', $pluginId, $animeId, $attempts),
            0,
            $previous,
        );
    }
}
