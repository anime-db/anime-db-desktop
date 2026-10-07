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

namespace App\Service\Import\V1;

use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\Storage;
use App\Entity\Studio;

/**
 * Everything {@see \App\Entity\Anime::fromV1()} needs from outside the aggregate: the v1
 * vocabulary, the heuristics and the reference entities that need deduplicating through the
 * database. The entity depends on this abstraction, never on the implementation, and has no
 * repository of its own.
 *
 * @internal
 */
interface V1AnimeResolverInterface
{
    public function resolveType(V1AnimeRecord $record): AnimeType;

    /** The status the record's v1 labels spell out, or the default when they spell none. */
    public function resolveWatchStatus(V1AnimeRecord $record): WatchStatus;

    /** @return list<Label> labels left once the status labels are taken out */
    public function resolveLabels(V1AnimeRecord $record): array;

    /** @return list<Studio> */
    public function resolveStudios(V1AnimeRecord $record): array;

    /** Null when the record points at no usable storage. */
    public function resolveStorage(V1AnimeRecord $record): ?Storage;

    public function resolveGenres(V1AnimeRecord $record): V1GenreSet;

    /** @return list<V1AlternativeName> trimmed and without duplicates */
    public function resolveNames(V1AnimeRecord $record): array;

    public function resolveNotes(V1AnimeRecord $record): ?string;
}
