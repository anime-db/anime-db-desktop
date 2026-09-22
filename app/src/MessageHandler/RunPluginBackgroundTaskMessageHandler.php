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
use App\Message\RunPluginBackgroundTaskMessage;
use App\Service\Plugin\BackgroundTaskHandlerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consumed on the `plugins` transport (issue #701) by its own long-lived process
 * (native/supervisor/plugins-consumer.js), separate from the `async`/`media` worker so a
 * long-running plugin task can never occupy the worker `PushSyncMessage` depends on.
 *
 * The plugin that queued the task may be gone by the time this runs — removed, disabled, or the
 * manifest simply no longer declaring a handler — since `submit()` does not check for one at
 * queue time (contracts, {@see \AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface}).
 * `failure_transport` is deliberately unconfigured (messenger.yaml) — there is nobody to triage a
 * `messenger:failed:*` queue on this single-user desktop app — so that case is logged and dropped
 * here rather than thrown, the same stance {@see DownloadAnimeMediaMessageHandler}
 * takes for its own "nothing left to apply this to" case.
 */
#[AsMessageHandler]
final class RunPluginBackgroundTaskMessageHandler
{
    public function __construct(
        private readonly BackgroundTaskHandlerRegistry $handlers,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunPluginBackgroundTaskMessage $message): void
    {
        $handler = $this->handlers->find(new PluginId($message->pluginId));
        if ($handler === null) {
            $this->logger->warning('Discarding a queued background task: no handler is registered for its plugin.', [
                'pluginId' => $message->pluginId,
                'taskName' => $message->task->name,
            ]);

            return;
        }

        $handler->handle($message->task);
    }
}
