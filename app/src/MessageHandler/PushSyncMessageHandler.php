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

namespace App\MessageHandler;

use AnimeDb\PluginContracts\Sync\SyncItem;
use App\Entity\Anime;
use App\Entity\ValueObject\PluginId;
use App\Message\PushSyncMessage;
use App\Service\Plugin\SyncRegistry;
use App\Service\Plugin\WatchStatusMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Same no-catch stance as IndexAnimeMessageHandler: a plugin's push() failure (network error,
 * external source down, ...) is deliberately left to propagate so the `async` transport's own
 * retry_strategy (issue #97) handles the retry, and Messenger's built-in failure logging covers
 * the case all retries are exhausted — no bespoke try/catch/log needed here. If one plugin's
 * push() fails, the remaining plugins in this run are skipped and retried alongside it on the
 * next attempt; re-pushing to a plugin that already succeeded is harmless, since push is the
 * safe sync direction (issue #214) — local state is the source of truth for what's sent, so
 * there is no collision/deduplication concern the way there is for pull.
 */
#[AsMessageHandler]
final class PushSyncMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SyncRegistry $syncRegistry,
    ) {
    }

    public function __invoke(PushSyncMessage $message): void
    {
        $anime = $this->entityManager->find(Anime::class, $message->animeId);
        if ($anime === null) {
            // Deleted (or the transaction that changed it never committed) by the time this
            // message is processed — nothing left to push.
            return;
        }

        $status = WatchStatusMapper::toSyncStatus($anime->getWatchStatus());

        foreach ($this->syncRegistry->allActive() as $id => $sync) {
            $externalId = $anime->getExternalId(new PluginId($id), $sync);
            if ($externalId === null) {
                // This plugin doesn't recognize any of the anime's source URLs — nothing to
                // push it under.
                continue;
            }

            $sync->push(new SyncItem($externalId, $status, $anime->getTitle()));
        }
    }
}
