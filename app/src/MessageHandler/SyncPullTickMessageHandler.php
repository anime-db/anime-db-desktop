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

namespace App\MessageHandler;

use App\Entity\ValueObject\PluginId;
use App\Message\SyncPullMessage;
use App\Message\SyncPullTickMessage;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\SyncPullGate;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs on every {@see \App\Scheduler\SyncPullSchedule} tick (issue #870): for each active sync
 * plugin that passes {@see SyncPullGate::isDue()} it queues a {@see SyncPullMessage} on the `sync`
 * transport. The pull itself runs in that transport's consumer, not in the schedule's one.
 */
#[AsMessageHandler]
final class SyncPullTickMessageHandler
{
    public function __construct(
        private readonly SyncRegistry $syncRegistry,
        private readonly SyncPullGate $gate,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SyncPullTickMessage $message): void
    {
        foreach ($this->syncRegistry->allActive() as $id => $sync) {
            if ($this->gate->isDue(new PluginId((string) $id))) {
                $this->bus->dispatch(new SyncPullMessage((string) $id));
            }
        }
    }
}
