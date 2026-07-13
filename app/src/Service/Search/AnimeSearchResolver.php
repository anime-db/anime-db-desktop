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

namespace App\Service\Search;

use Meilisearch\Client;
use Meilisearch\Exceptions\ExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the anime list search box (issue #199) into a list of matching Anime ids via
 * Meilisearch. Any Meilisearch failure — connection refused, timeout, a malformed response,
 * all implementing Meilisearch\Exceptions\ExceptionInterface — is caught here and turned into
 * null instead of propagating: the caller (AnimeListController) then leaves
 * AnimeListFilter::$name in place, which makes AnimeRepository fall back to the FTS5
 * quick-filter (issue #195) already wired to that field. Same degrade-not-fail philosophy as
 * window.animeDb.pickFolder (issue #165). An empty list (not null) means Meilisearch itself
 * answered and genuinely found nothing — the caller must not fall back to FTS5 in that case.
 */
class AnimeSearchResolver
{
    private const INDEX_UID = 'anime';

    /**
     * Meilisearch defaults a search to 20 hits. AnimeListController applies its own
     * watch_status/genre/etc. filters and limit/offset pagination on top of the ids this
     * class returns, so truncating here would silently drop matches from later pages. A
     * personal anime catalog (see .claude-docs/decisions.md) never gets close to this size.
     */
    private const MAX_HITS = 10_000;

    public function __construct(
        private readonly Client $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<int>|null */
    public function tryResolveIds(string $query): ?array
    {
        try {
            $hits = $this->client->index(self::INDEX_UID)->search($query, [
                'attributesToRetrieve' => ['id'],
                'matchingStrategy' => 'frequency',
                'limit' => self::MAX_HITS,
            ])->getHits();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Meilisearch search failed, falling back to the FTS5 quick-filter.', [
                'query' => $query,
                'exception' => $e,
            ]);

            return null;
        }

        return array_values(array_map(static fn (array $hit): int => (int) $hit['id'], $hits));
    }
}
