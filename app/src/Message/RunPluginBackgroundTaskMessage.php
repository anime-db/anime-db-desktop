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

namespace App\Message;

use AnimeDb\PluginContracts\Background\BackgroundTask;

/**
 * Host-side envelope around a plugin's {@see BackgroundTask}, dispatched on the `plugins`
 * transport (issue #701, part 1 of 3 for #684) by {@see \App\Service\Plugin\BackgroundTaskQueue}.
 *
 * Carries the owning plugin's id alongside the task itself: {@see BackgroundTask} has no id field
 * of its own (contracts issue, `BackgroundTaskQueueInterface::submit()` deliberately does not take
 * one either — the queue instance a plugin gets is already scoped to its own id), so
 * {@see \App\MessageHandler\RunPluginBackgroundTaskMessageHandler} needs it carried separately to
 * know which plugin's {@see \AnimeDb\PluginContracts\Background\BackgroundTaskHandlerInterface} to
 * route the unwrapped task to.
 */
final readonly class RunPluginBackgroundTaskMessage
{
    public function __construct(
        public string $pluginId,
        public BackgroundTask $task,
    ) {
    }
}
