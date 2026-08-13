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

namespace App\Repository;

use App\Entity\Anime;
use App\Entity\AnimeSyncState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Thin persistence wrapper (issue #365), mirroring SyncReviewItemRepository: the reconciliation
 * logic that decides when to create/update a row is the sync engine's job (issue #366,
 * {@see \App\Service\Sync\SyncConvergenceService}), out of scope here.
 *
 * Every method takes an optional $entityManager override, defaulting to the one injected by DI:
 * PullSyncService's create-conflict recovery path (issue #297) keeps issuing writes through a
 * throwaway EntityManager sharing the original one's connection once a lost race has closed the
 * original — the snapshot this class maintains must follow that same recovery instance
 * (.claude-docs/sync.md pitfall #13, "recovery-EM теряет снимок"), not silently write through
 * the now-closed original.
 */
class AnimeSyncStateRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function find(Anime $anime, string $participantId, ?EntityManagerInterface $entityManager = null): ?AnimeSyncState
    {
        return ($entityManager ?? $this->entityManager)->find(AnimeSyncState::class, [
            'anime' => $anime,
            'participantId' => $participantId,
        ]);
    }

    /** @return AnimeSyncState[] */
    public function findByAnime(Anime $anime, ?EntityManagerInterface $entityManager = null): array
    {
        return ($entityManager ?? $this->entityManager)->getRepository(AnimeSyncState::class)->findBy(['anime' => $anime]);
    }

    public function save(AnimeSyncState $state, ?EntityManagerInterface $entityManager = null): void
    {
        $entityManager ??= $this->entityManager;
        $entityManager->persist($state);
        $entityManager->flush();
    }
}
