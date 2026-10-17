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

use AnimeDb\PluginContracts\Catalog\AnimeAddedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeChangedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\AnimeRemovedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Event\Catalog\AnimeAdded;
use App\Event\Catalog\AnimeChanged;
use App\Event\Catalog\AnimeRemoved;
use App\Service\Plugin\AnimeFieldProjector;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publishes the plugin-facing catalog events as a projection of the internal ones (issue #1075):
 * {@see AnimeAdded} → {@see AnimeAddedEvent}, {@see AnimeChanged} → {@see AnimeChangedEvent},
 * {@see AnimeRemoved} → {@see AnimeRemovedEvent}, and `Unlinked` out of an {@see AnimeChanged}
 * whose `storage` went from set to null. There is no other dispatch point for these events: the
 * internal ones are delivered only once the change is final (see
 * {@see \App\DomainEvent\DomainEventPublisher}), so the plugin events never go out before the
 * commit and are not lost on a write that bypassed the UnitOfWork.
 *
 * Low priority, so every other subscriber of the internal event has already run. What plugins may
 * see is decided by {@see AnimeFieldProjector}; a change that projects to nothing publishes nothing.
 * A plugin listener is isolated by {@see \App\EventDispatcher\PluginIsolatingEventDispatcher}.
 *
 * A record that never had a folder, and `FilesMissing` of a scan, give no `Unlinked`: only the
 * `storage` going to null does.
 */
final class CatalogPluginEventsSubscriber implements EventSubscriberInterface
{
    private const int PRIORITY = -100;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AnimeFieldProjector $projector,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AnimeAdded::class => ['onAdded', self::PRIORITY],
            AnimeChanged::class => ['onChanged', self::PRIORITY],
            AnimeRemoved::class => ['onRemoved', self::PRIORITY],
        ];
    }

    public function onAdded(AnimeAdded $event): void
    {
        $this->eventDispatcher->dispatch(new AnimeAddedEvent(new AnimeId($event->animeId)));
    }

    public function onChanged(AnimeChanged $event): void
    {
        $changes = $this->projector->projectChanges($event->changes);
        if ($changes !== []) {
            $this->eventDispatcher->dispatch(new AnimeChangedEvent(new AnimeId($event->animeId), $changes));
        }

        $storage = $event->changes['storage'] ?? null;
        if ($storage !== null && $storage->before !== null && $storage->after === null) {
            $this->eventDispatcher->dispatch(new AnimeFilesChangedEvent(new AnimeId($event->animeId), FilesChangeReason::Unlinked));
        }
    }

    public function onRemoved(AnimeRemoved $event): void
    {
        $this->eventDispatcher->dispatch(new AnimeRemovedEvent(new AnimeId($event->animeId), $this->projector->projectSnapshot($event->snapshot)));
    }
}
