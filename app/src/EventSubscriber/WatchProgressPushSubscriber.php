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

namespace App\EventSubscriber;

use App\Event\WatchProgressChangedManuallyEvent;
use App\Message\PushSyncMessage;
use App\Service\Plugin\SyncRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Application-level reaction to the domain event WatchProgressChangedManuallyEvent (issue #371):
 * dispatches one PushSyncMessage per active sync plugin ({@see SyncRegistry::allActive()}) onto
 * the `async` transport, same as the old Doctrine preUpdate listener (AnimeSyncPushListener) did,
 * but now driven by a user-driven domain method instead of an inspected Doctrine change set — see
 * PushSyncMessageHandler for why dispatching onto a queue rather than pushing inline is the shape
 * here (transport retry_strategy, issue #97).
 *
 * One message per plugin, not one message fanning out to every plugin (issue #868): each message
 * carries the target plugin's id and gets its own independent retries, so a push failure in one
 * plugin's message can no longer cause the plugins listed after it in a single shared message to
 * lose the edit once that message's retries are exhausted.
 *
 * Anime::applyWatchProgress() (the sync-apply path) never records WatchProgressChangedManuallyEvent,
 * so a pull-applied change never reaches this subscriber at all — no suppressor needed to break
 * the pull->push echo (issue #352) for this trigger any more, the distinction is made in the
 * domain layer itself.
 */
final class WatchProgressPushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly SyncRegistry $syncRegistry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WatchProgressChangedManuallyEvent::class => 'onWatchProgressChangedManually',
        ];
    }

    public function onWatchProgressChangedManually(WatchProgressChangedManuallyEvent $event): void
    {
        $animeId = $this->requireId($event);
        $dispatchedAt = new \DateTimeImmutable();

        foreach ($this->syncRegistry->allActive() as $pluginId => $sync) {
            $this->messageBus->dispatch(new PushSyncMessage($animeId, $dispatchedAt, $pluginId));
        }
    }

    private function requireId(WatchProgressChangedManuallyEvent $event): int
    {
        return $event->id ?? throw new \LogicException('WatchProgressChangedManuallyEvent must carry an id at this point in its lifecycle.');
    }
}
