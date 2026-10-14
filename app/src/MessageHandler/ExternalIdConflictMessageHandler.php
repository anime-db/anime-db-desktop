<?php

declare(strict_types=1);

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

namespace App\MessageHandler;

use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\ValueObject\PluginId;
use App\Message\ExternalIdConflictMessage;
use App\Repository\AnimeRepository;
use App\Service\Sync\SyncReviewService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Raises (or extends) a PotentialDuplicate review item for an external id conflict. A signal for a
 * human: nothing is merged and the id stays with its owner. One item per pair of records: the
 * conflict is appended to an open item containing both records, a closed item that already carries
 * conflicts suppresses the pair for good, otherwise a new item is created.
 */
#[AsMessageHandler]
final class ExternalIdConflictMessageHandler
{
    public function __construct(
        private readonly AnimeRepository $animeRepository,
        private readonly SyncReviewService $reviewService,
    ) {
    }

    public function __invoke(ExternalIdConflictMessage $message): void
    {
        $found = $this->animeRepository->findByIds([$message->ownerId, $message->animeId]);
        if (!isset($found[$message->ownerId], $found[$message->animeId]) || $message->ownerId === $message->animeId) {
            return;
        }

        // The message is async: the owner may have lost the id since.
        $holder = $this->animeRepository->resolve(new PluginId($message->pluginId), $message->externalId);
        if ($holder === null || $holder->id !== $message->ownerId) {
            return;
        }

        $conflict = ['plugin_id' => $message->pluginId, 'external_id' => $message->externalId, 'owner_id' => $message->ownerId, 'anime_id' => $message->animeId];
        $suppressed = false;

        foreach ($this->reviewService->findAllByKind(SyncReviewItemKind::PotentialDuplicate) as $item) {
            /** @var list<int> $animeIds */
            $animeIds = $item->payload['anime_ids'] ?? [];
            if (!\in_array($message->ownerId, $animeIds, true) || !\in_array($message->animeId, $animeIds, true)) {
                continue;
            }

            if (!$item->isResolved()) {
                if ($item->addConflict($conflict)) {
                    $this->reviewService->save($item);
                }

                return;
            }

            if ($item->hasConflicts()) {
                $suppressed = true;
            }
        }

        if ($suppressed) {
            return;
        }

        $pair = [$message->ownerId, $message->animeId];
        sort($pair);
        $this->reviewService->create(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => $pair, 'conflicts' => [$conflict]]);
    }
}
