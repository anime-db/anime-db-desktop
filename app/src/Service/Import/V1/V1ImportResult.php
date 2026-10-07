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

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Outcome of {@see V1ImportService::import()} (issue #951): what was added, and the two real
 * losses — genres with no counterpart and 18+ genres left out on purpose, kept apart because the
 * first is a gap in our dictionary and the second a decision. Console and the later onboarding
 * screen render the same object.
 *
 * The report says what was added, not what was found in the foreign database, and makes no
 * promise about external ids: they appear only once a sync plugin has run.
 */
final class V1ImportResult
{
    /**
     * @param list<string> $unmappedGenreNames    distinct v1 genre names without a counterpart
     * @param list<string> $skippedStorageNames   v1 storages left out: no usable path
     * @param list<string> $episodesDroppedTitles titles whose episode count a non-series type cannot hold
     */
    public function __construct(
        public readonly int $animeCreated = 0,
        public readonly int $withStatusFromLabel = 0,
        public readonly int $withDefaultStatus = 0,
        public readonly int $namesJapanese = 0,
        public readonly int $namesRussian = 0,
        public readonly int $namesUnknownLocale = 0,
        public readonly int $sources = 0,
        public readonly int $descriptions = 0,
        public readonly int $studios = 0,
        public readonly int $labels = 0,
        public readonly int $genresMapped = 0,
        public readonly int $genresDroppedByDesign = 0,
        public readonly int $genresUnmapped = 0,
        public readonly array $unmappedGenreNames = [],
        public readonly int $coversImported = 0,
        public readonly int $coversMissing = 0,
        public readonly int $storagesCreated = 0,
        public readonly int $storagesUnavailable = 0,
        public readonly array $skippedStorageNames = [],
        public readonly int $endDatesSynthesized = 0,
        public readonly int $durationsCleared = 0,
        public readonly array $episodesDroppedTitles = [],
        public readonly int $needsAttention = 0,
    ) {
    }

    public function namesTotal(): int
    {
        return $this->namesJapanese + $this->namesRussian + $this->namesUnknownLocale;
    }

    /** @return list<string> */
    public function render(TranslatorInterface $translator): array
    {
        $lines = [
            $translator->trans('import_v1.report_created', [
                '%count%' => $this->animeCreated,
                '%fromLabel%' => $this->withStatusFromLabel,
                '%byDefault%' => $this->withDefaultStatus,
            ]),
            $translator->trans('import_v1.report_names', [
                '%count%' => $this->namesTotal(),
                '%ja%' => $this->namesJapanese,
                '%ru%' => $this->namesRussian,
                '%none%' => $this->namesUnknownLocale,
            ]),
            $translator->trans('import_v1.report_related', [
                '%sources%' => $this->sources,
                '%descriptions%' => $this->descriptions,
                '%studios%' => $this->studios,
                '%labels%' => $this->labels,
            ]),
            $translator->trans('import_v1.report_genres', ['%count%' => $this->genresMapped]),
            $translator->trans('import_v1.report_genres_dropped', ['%count%' => $this->genresDroppedByDesign]),
            $translator->trans('import_v1.report_genres_unmapped', [
                '%count%' => $this->genresUnmapped,
                '%names%' => implode(', ', $this->unmappedGenreNames),
            ]),
            $translator->trans('import_v1.report_covers', [
                '%imported%' => $this->coversImported,
                '%missing%' => $this->coversMissing,
            ]),
            $translator->trans('import_v1.report_storages', [
                '%count%' => $this->storagesCreated,
                '%unavailable%' => $this->storagesUnavailable,
            ]),
        ];

        if ($this->skippedStorageNames !== []) {
            $lines[] = $translator->trans('import_v1.report_storages_skipped', ['%names%' => implode(', ', $this->skippedStorageNames)]);
        }
        if ($this->episodesDroppedTitles !== []) {
            $lines[] = $translator->trans('import_v1.report_episodes_dropped', [
                '%count%' => \count($this->episodesDroppedTitles),
                '%titles%' => implode(', ', $this->episodesDroppedTitles),
            ]);
        }
        if ($this->endDatesSynthesized > 0) {
            $lines[] = $translator->trans('import_v1.report_end_dates', ['%count%' => $this->endDatesSynthesized]);
        }
        if ($this->needsAttention > 0) {
            $lines[] = $translator->trans('import_v1.report_needs_attention', ['%count%' => $this->needsAttention]);
        }

        return $lines;
    }
}
