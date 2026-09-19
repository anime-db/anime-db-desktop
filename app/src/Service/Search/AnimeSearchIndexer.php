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

namespace App\Service\Search;

use App\Entity\Anime;
use App\Entity\AnimeName;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Label;
use App\Entity\Studio;
use Meilisearch\Client;

/**
 * Indexes Anime entities into the Meilisearch "anime" index. Not wired into Anime's
 * persistence lifecycle here (see issue #197 for that) — this is a plain synchronous
 * indexing API called by whoever needs it (bulk reindex command, lifecycle listener, ...).
 *
 * matchingStrategy is deliberately absent from configureIndex(): despite the issue
 * describing it as an index setting, it is a per-search-request parameter, not a
 * persisted index setting — PATCH /indexes/{uid}/settings rejects it outright ("Unknown
 * field `matchingStrategy`"), verified empirically against meilisearch 1.13.0. Whatever
 * component issues the actual search query is responsible for passing
 * `matchingStrategy: frequency` in the search params (see context/tech_decisions.md).
 */
final class AnimeSearchIndexer
{
    private const INDEX_UID = 'anime';

    /**
     * Manually curated word families that typo-tolerance cannot bridge (verified against
     * docs/spikes/49-meilisearch-ru-morphology.md, раздел 3/5.1): editing distance between
     * canonical form and inflected form exceeds any reasonable typoTolerance threshold.
     * Extend this dictionary as new problem words are found in the real catalog — it is
     * not meant to cover the whole Russian case system, only the exceptions typo-tolerance
     * misses (see spike, раздел 6, пункт 2).
     */
    private const SYNONYMS = [
        'имя' => ['имени', 'именем'],
        'имени' => ['имя', 'именем'],
        'именем' => ['имя', 'имени'],
    ];

    public function __construct(
        private readonly Client $client,
    ) {
    }

    public function index(Anime $anime): void
    {
        $index = $this->client->index(self::INDEX_UID);

        $task = $index->addDocuments([$this->toDocument($anime)], 'id');
        $index->waitForTask($task['taskUid']);
    }

    public function delete(int $animeId): void
    {
        $index = $this->client->index(self::INDEX_UID);

        $task = $index->deleteDocument($animeId);
        $index->waitForTask($task['taskUid']);
    }

    /**
     * Removes every document from the index without touching its settings. Call this before
     * a full rebuild so documents for records no longer present in the source data do not
     * survive the rebuild alongside the fresh ones.
     */
    public function clearIndex(): void
    {
        $index = $this->client->index(self::INDEX_UID);

        $task = $index->deleteAllDocuments();
        $index->waitForTask($task['taskUid']);
    }

    /**
     * Idempotent: safe to call on every app start/deploy. PATCH /settings auto-creates
     * the index if it does not exist yet (verified empirically), so no explicit
     * createIndex() call is needed beforehand.
     */
    public function configureIndex(): void
    {
        $index = $this->client->index(self::INDEX_UID);

        $task = $index->updateSettings([
            'searchableAttributes' => ['title', 'names'],
            'filterableAttributes' => ['genres', 'themes', 'demographic', 'watch_status', 'studios', 'labels'],
            'synonyms' => self::SYNONYMS,
            'typoTolerance' => [
                'minWordSizeForTypos' => [
                    'oneTypo' => 5,
                    'twoTypos' => 6,
                ],
            ],
        ]);
        $index->waitForTask($task['taskUid']);
    }

    /**
     * @return array<string, mixed>
     */
    private function toDocument(Anime $anime): array
    {
        return [
            'id' => $anime->id,
            'title' => $anime->getTitle(),
            'names' => array_values(array_map(
                static fn (AnimeName $name): string => $name->name,
                $anime->getNames()->toArray(),
            )),
            'genres' => array_map(static fn (GenreCode $code): string => $code->value, $anime->getGenreCodes()),
            'themes' => array_map(static fn (ThemeCode $code): string => $code->value, $anime->getThemeCodes()),
            'demographic' => $anime->getDemographic()?->value,
            'watch_status' => $anime->getWatchStatus()->value,
            'studios' => array_values(array_map(
                static fn (Studio $studio): string => $studio->name,
                $anime->getStudios()->toArray(),
            )),
            'labels' => array_values(array_map(
                static fn (Label $label): string => $label->name,
                $anime->getLabels()->toArray(),
            )),
        ];
    }
}
