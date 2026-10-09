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

namespace App\Entity\ValueObject;

use App\Entity\Enum\AnimeType;

/**
 * The outcome of Anime::planTypeChange(): the type and the columns the change touches as they are
 * after it, and what the change drops. Only a series becoming a movie drops anything, and it
 * cannot be undone, so {@see self::isLossy()} is what asks the user for a separate confirmation:
 * it holds even when the dropped values happen to be empty.
 */
final readonly class AnimeTypeChange
{
    public function __construct(
        public AnimeType $from,
        public AnimeType $to,
        public ?int $episodesCount,
        public ?int $watchedEpisodes,
        public ?\DateTimeImmutable $datePremiere,
        public ?\DateTimeImmutable $dateEnd,
        public ?int $lostEpisodesCount = null,
        public ?int $lostWatchedEpisodes = null,
        public ?\DateTimeImmutable $lostDateEnd = null,
    ) {
    }

    public function isLossy(): bool
    {
        return $this->from !== AnimeType::Movie && $this->to === AnimeType::Movie;
    }
}
