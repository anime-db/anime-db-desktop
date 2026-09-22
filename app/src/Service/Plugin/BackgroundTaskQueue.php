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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Background\BackgroundTask;
use AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface;
use App\Entity\ValueObject\PluginId;
use App\Message\RunPluginBackgroundTaskMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Scoped to a single plugin's own {@see PluginId} — see
 * {@see DependencyInjection\Compiler\BackgroundTaskQueueScopePass}, which constructs one instance
 * per installed plugin and is the only place a plugin ever obtains one, the same way
 * {@see PluginDataStore} is scoped. `submit()` has no way to name a different plugin's id: the
 * constructor already fixed it, so a plugin can never queue a task under another plugin's
 * identity through this class.
 *
 * No deduplication and no way to inspect the queue's state, by contract
 * ({@see BackgroundTaskQueueInterface}) — this class only wraps $task with the owning plugin's id
 * and hands it to the message bus; everything else about ordering, retries or duplicate delivery
 * is between the `plugins` transport and
 * {@see \App\MessageHandler\RunPluginBackgroundTaskMessageHandler}.
 */
final class BackgroundTaskQueue implements BackgroundTaskQueueInterface
{
    public function __construct(
        private readonly PluginId $pluginId,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function submit(BackgroundTask $task): void
    {
        $this->bus->dispatch(new RunPluginBackgroundTaskMessage((string) $this->pluginId, $task));
    }
}
