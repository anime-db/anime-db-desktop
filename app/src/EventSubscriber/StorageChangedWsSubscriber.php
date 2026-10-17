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

use App\Event\StorageChanged;
use App\Service\WsPublisher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publishes `storage.changed` over WebSocket for a {@see StorageChanged} domain event. The event is
 * delivered after the commit, so native, which reacts by running `app:storage:list` in another
 * process, reads the final state, and a rolled-back change never reaches it.
 */
final class StorageChangedWsSubscriber implements EventSubscriberInterface
{
    public const string EVENT = 'storage.changed';

    public function __construct(
        private readonly WsPublisher $wsPublisher,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [StorageChanged::class => 'onStorageChanged'];
    }

    public function onStorageChanged(StorageChanged $event): void
    {
        $this->wsPublisher->publish(self::EVENT, ['id' => $event->storageId]);
    }
}
