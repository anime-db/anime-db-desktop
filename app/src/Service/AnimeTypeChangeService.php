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

namespace App\Service;

use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use App\Message\IndexAnimeMessage;
use App\Message\SyncSeedMessage;
use App\Service\JobLock\JobLockService;
use App\Service\Plugin\SyncRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Changes the type of a catalog entry in place (issue #1001): the same row, so the id, the downloads,
 * the pending sync push, the dates of adding and of the files check, the external ids and the labels
 * all stay. What the new type does to the entry is decided by {@see Anime::planTypeChange()}; this
 * class only writes it: one UPDATE by id of `type` and the columns the type touches.
 *
 * Refused, with nothing changed, while an active sync plugin holds its {@see SyncSeedMessage::jobKey()}
 * lock, as the deletion of an entry is ({@see AnimeDeleteService}).
 *
 * The UPDATE bypasses the unit of work, so in the same request the entity manager is cleared (the
 * entity passed in is detached, load it again to see the new type), `date_update` is raised and the
 * entry is queued for re-indexing. Sync is not involved: a pull never changes a type, the type of
 * the source is only compared with it elsewhere.
 */
final class AnimeTypeChangeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SyncRegistry $syncRegistry,
        private readonly JobLockService $jobLockService,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @throws \App\Entity\Exception\InvalidAnimeTypeChangeException the entry has this type already, or the result is forbidden
     */
    public function change(Anime $anime, AnimeType $targetType): AnimeTypeChangeOutcome
    {
        $animeId = $anime->id ?? throw new \LogicException('Anime must be persisted before its type can be changed.');
        $change = $anime->planTypeChange($targetType);

        if ($this->isSyncRunning()) {
            return AnimeTypeChangeOutcome::SyncRunning;
        }

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE anime SET type = ?, episodes_count = ?, watched_episodes = ?, date_premiere = ?, date_end = ?, date_update = ? WHERE id = ?',
            [
                $change->to->value,
                $change->episodesCount,
                $change->watchedEpisodes,
                $change->datePremiere?->getTimestamp(),
                $change->dateEnd?->getTimestamp(),
                time(),
                $animeId,
            ],
        );
        $this->entityManager->clear();

        $this->bus->dispatch(new IndexAnimeMessage($animeId));

        return AnimeTypeChangeOutcome::Changed;
    }

    private function isSyncRunning(): bool
    {
        foreach ($this->syncRegistry->allActive() as $pluginId => $_) {
            if ($this->jobLockService->isLocked(SyncSeedMessage::jobKey((string) $pluginId))) {
                return true;
            }
        }

        return false;
    }
}
