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

namespace App\EventSubscriber;

use App\Entity\Anime;
use App\Event\WatchProgressChangedByUserEvent;
use App\Message\PushSyncMessage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Application-level reaction to the domain event WatchProgressChangedByUserEvent (issue #371):
 * dispatches PushSyncMessage onto the `async` transport, same as the old Doctrine preUpdate
 * listener (AnimeSyncPushListener) did, but now driven by a user-driven domain method instead of
 * an inspected Doctrine change set — see PushSyncMessageHandler for why dispatching onto a queue
 * rather than pushing inline is the shape here (transport retry_strategy, issue #97).
 *
 * Anime::applyWatchProgress() (the sync-apply path) never records WatchProgressChangedByUserEvent,
 * so a pull-applied change never reaches this subscriber at all — no suppressor needed to break
 * the pull->push echo (issue #352) for this trigger any more, the distinction is made in the
 * domain layer itself.
 */
final class WatchProgressPushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WatchProgressChangedByUserEvent::class => 'onWatchProgressChangedByUser',
        ];
    }

    public function onWatchProgressChangedByUser(WatchProgressChangedByUserEvent $event): void
    {
        $this->messageBus->dispatch(new PushSyncMessage($this->requireId($event->anime)));
    }

    private function requireId(Anime $anime): int
    {
        return $anime->id ?? throw new \LogicException('Anime must have an id at this point in its lifecycle.');
    }
}
