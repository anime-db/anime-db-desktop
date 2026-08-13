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

namespace App\Service\Sync;

use App\Entity\Anime;
use App\Entity\AnimeName;
use App\Entity\Enum\SyncReviewItemKind;
use App\Service\Search\AnimeSearchResolver;

/**
 * Cross-vendor duplicate heuristic (issue #268), run by PullSyncService for a pulled item that
 * PullSyncService::pull()'s indexByExternalId() lookup could not match to an already-known local
 * Anime: the same title independently added on two sources (each pull run only ever sees its own
 * vendor's external_id) would otherwise land as two separate rows. This never merges anything —
 * it only flags the cluster into SyncReviewService (issue #267 store) for a human to resolve.
 *
 * $anime is expected to be freshly created and flushed (has an id) but not yet indexed into
 * Meilisearch by AnimeSearchIndexListener's async IndexAnimeMessage — so a match this returns is
 * always a *different*, pre-existing catalog row, never $anime's own not-yet-indexed self. The id
 * exclusion below is defensive, not load-bearing on that timing.
 *
 * SCORE_THRESHOLD is fixed and deliberately high rather than configurable: the issue calls for a
 * "conservative" bar because a false-positive flag (two genuinely different titles clustered
 * together) is worse than a false negative (a real duplicate slipping through unflagged, which a
 * later pull or manual review can still catch).
 */
final class CrossVendorDuplicateDetector
{
    private const SCORE_THRESHOLD = 0.9;

    public function __construct(
        private readonly AnimeSearchResolver $searchResolver,
        private readonly SyncReviewService $reviewService,
    ) {
    }

    public function detect(Anime $anime): void
    {
        $id = $anime->id ?? throw new \LogicException('Anime must have an id at this point in its lifecycle.');
        $matchedIds = [];

        foreach ($this->queries($anime) as $query) {
            $matches = $this->searchResolver->tryResolveMatches($query);
            if ($matches === null) {
                // Meilisearch is unreachable — skip the heuristic entirely for this item rather
                // than raise a cluster built from only some of its names.
                return;
            }

            foreach ($matches as $match) {
                if ($match->rankingScore >= self::SCORE_THRESHOLD && $match->id !== $id) {
                    $matchedIds[$match->id] = $match->id;
                }
            }
        }

        if ($matchedIds === []) {
            return;
        }

        $clusterIds = [...array_values($matchedIds), $id];
        sort($clusterIds);

        $this->reviewService->create(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => $clusterIds]);
    }

    /** @return list<string> */
    private function queries(Anime $anime): array
    {
        $names = array_map(static fn (AnimeName $name): string => $name->name, $anime->getNames()->toArray());

        return array_values(array_unique([$anime->getTitle(), ...$names]));
    }
}
