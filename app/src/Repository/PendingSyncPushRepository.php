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
use App\Entity\PendingSyncPush;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Thin persistence wrapper for {@see PendingSyncPush} (issue #862), mirroring
 * {@see AnimeSyncStateRepository}: every method takes an optional $entityManager override,
 * defaulting to the one injected by DI, for the same recovery-EM reason documented there.
 */
class PendingSyncPushRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function isPending(Anime $anime, string $participantId, ?EntityManagerInterface $entityManager = null): bool
    {
        return $this->find($anime, $participantId, $entityManager ?? $this->entityManager) !== null;
    }

    public function markPending(Anime $anime, string $participantId, ?EntityManagerInterface $entityManager = null): void
    {
        $entityManager ??= $this->entityManager;

        if ($this->find($anime, $participantId, $entityManager) !== null) {
            return;
        }

        $entityManager->persist(new PendingSyncPush($anime, $participantId));
        $entityManager->flush();
    }

    public function clearPending(Anime $anime, string $participantId, ?EntityManagerInterface $entityManager = null): void
    {
        $entityManager ??= $this->entityManager;

        $existing = $this->find($anime, $participantId, $entityManager);
        if ($existing === null) {
            return;
        }

        $entityManager->remove($existing);
        $entityManager->flush();
    }

    private function find(Anime $anime, string $participantId, EntityManagerInterface $entityManager): ?PendingSyncPush
    {
        return $entityManager->find(PendingSyncPush::class, [
            'anime' => $anime,
            'participantId' => $participantId,
        ]);
    }
}
