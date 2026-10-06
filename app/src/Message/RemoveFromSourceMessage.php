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

namespace App\Message;

/**
 * Dispatched on the `async` transport after a local deletion with "also delete from the lists on the
 * sources" (issue #918), once per target source. Carries the whole (plugin id, external id) pair: the
 * entry is already gone when the message is handled, so nothing can be re-read by an id.
 *
 * The message only wakes the handler up; what to do is decided by the
 * {@see \App\Entity\SyncTombstone} it finds there, see {@see \App\Service\Sync\SourceRemovalService}.
 */
final readonly class RemoveFromSourceMessage
{
    public function __construct(
        public string $pluginId,
        public string $externalId,
    ) {
    }
}
