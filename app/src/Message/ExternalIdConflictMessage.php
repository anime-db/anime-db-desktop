<?php

declare(strict_types=1);

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

namespace App\Message;

/**
 * A plugin external id recognised from a record's links is already pinned to another record, so
 * the record $animeId does not get it. {@see \App\MessageHandler\ExternalIdConflictMessageHandler}
 * turns it into a "needs review" item. Dispatched with DispatchAfterCurrentBusStamp: the owner must
 * be flushed before the handler looks for it.
 */
final readonly class ExternalIdConflictMessage
{
    public function __construct(
        public string $pluginId,
        public string $externalId,
        public int $ownerId,
        public int $animeId,
    ) {
    }
}
