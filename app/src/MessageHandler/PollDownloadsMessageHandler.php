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

use App\Message\PollDownloadsMessage;
use App\Service\Download\DownloadCompletionPoller;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs {@see DownloadCompletionPoller::poll()} on every {@see PollDownloadsMessage} tick (issue
 * #685) — this is what actually makes {@see \App\Scheduler\DownloadsPollSchedule} do anything.
 * No locking here on top of poll() itself: it is already idempotent (see its own class docblock),
 * so a tick landing while the previous one is still in flight, or overlapping with the
 * app-startup run in native/supervisor/downloads-poll.js, is safe by construction.
 */
#[AsMessageHandler]
final class PollDownloadsMessageHandler
{
    public function __construct(private readonly DownloadCompletionPoller $poller)
    {
    }

    public function __invoke(PollDownloadsMessage $message): void
    {
        $this->poller->poll();
    }
}
