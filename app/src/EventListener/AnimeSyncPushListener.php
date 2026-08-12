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

namespace App\EventListener;

use App\Entity\Anime;
use App\Message\PushSyncMessage;
use App\Service\Sync\PullPushSuppressor;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Push direction of sync (issue #214): whenever an Anime's watch progress changes, dispatches
 * PushSyncMessage onto the `async` transport so the actual SyncInterface::push() calls (see
 * PushSyncMessageHandler) run in the consumer process, where the transport's own retry_strategy
 * (issue #97) absorbs a plugin/network failure without failing the HTTP request that changed
 * the status.
 *
 * A preUpdate listener rather than the postUpdate one AnimeSearchIndexListener uses: reindexing
 * is cheap and safe to run on every change, but pushing to an external source on every field
 * touch (title edit, rating change, ...) would be wasteful and surprising — only a watch-progress
 * change should trigger a push, which needs the change set preUpdate provides.
 *
 * The trigger covers both halves of the reconciliation unit (issue #365): watchStatus alone used
 * to be the only check, which missed a SeriesAnime episode-only edit (e.g. 5/12 -> 6/12 while
 * still Watching, where watchStatus itself never changes) — watchedEpisodes and
 * watchProgressUpdatedAt (the latter bumped by Anime::applyWatchProgress(), see its docblock) are
 * included for the same reason. Moving this fully into the domain layer (so a manual edit path
 * decides the trigger itself, with origin-aware propagation instead of a single global mute) is
 * sync-engine work, deliberately deferred — see issue #365's own scope note.
 *
 * $pushSuppressor breaks the pull->push echo loop (issue #352): a watch-progress change made by
 * PullSyncService::pull() itself must not be echoed back out as a push — see
 * PullPushSuppressor's docblock. A plain user-driven edit (outside any pull() run) still
 * dispatches as before, since the suppressor is only active for the duration of a pull().
 */
#[AsDoctrineListener(event: Events::preUpdate)]
final class AnimeSyncPushListener
{
    private const TRIGGER_FIELDS = ['watchStatus', 'watchedEpisodes', 'watchProgressUpdatedAt'];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly PullPushSuppressor $pushSuppressor,
    ) {
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Anime) {
            return;
        }

        if (!$this->hasChangedProgressField($args)) {
            return;
        }

        if ($this->pushSuppressor->isSuppressed()) {
            return;
        }

        $this->messageBus->dispatch(new PushSyncMessage($this->requireId($entity)));
    }

    private function hasChangedProgressField(PreUpdateEventArgs $args): bool
    {
        foreach (self::TRIGGER_FIELDS as $field) {
            if ($args->hasChangedField($field)) {
                return true;
            }
        }

        return false;
    }

    private function requireId(Anime $anime): int
    {
        return $anime->id ?? throw new \LogicException('Anime must have an id at this point in its lifecycle.');
    }
}
