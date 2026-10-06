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

use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\SyncReviewItem;
use App\Repository\SyncReviewItemRepository;

/**
 * Thin facade over SyncReviewItemRepository (issue #267): the "needs review" list a sync run
 * raises (cross-vendor dedup detection, source-side removal, ...) is out of scope here — this
 * only creates/lists/resolves the persisted entries those future detectors will produce.
 */
final class SyncReviewService
{
    public function __construct(private readonly SyncReviewItemRepository $repository)
    {
    }

    /** @param array<string, mixed> $payload */
    public function create(SyncReviewItemKind $kind, array $payload): SyncReviewItem
    {
        $item = new SyncReviewItem($kind, $payload);
        $this->repository->save($item);

        return $item;
    }

    /** @return SyncReviewItem[] */
    public function findUnresolved(): array
    {
        return $this->repository->findAllUnresolvedOrderedByCreatedAt();
    }

    /**
     * Backs the settings sidebar's "needs correction" badge (issue #822) — see
     * {@see SyncReviewItemRepository::countUnresolvedByKind()} for why this is a count query.
     */
    public function countUnresolvedNeedsCorrection(): int
    {
        return $this->repository->countUnresolvedByKind(SyncReviewItemKind::NeedsCorrection);
    }

    public function resolve(SyncReviewItem $item): void
    {
        $item->resolve();
        $this->repository->save($item);
    }

    /**
     * Tidies the unresolved items that point at a deleted catalog entry (issue #916), see
     * {@see SyncReviewItem::forgetAnime()}.
     */
    public function forgetAnime(int $animeId): void
    {
        foreach ($this->repository->findAllUnresolvedOrderedByCreatedAt() as $item) {
            $item->forgetAnime($animeId);
        }

        $this->repository->flush();
    }
}
