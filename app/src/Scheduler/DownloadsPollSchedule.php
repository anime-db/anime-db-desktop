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

namespace App\Scheduler;

use App\Message\PollDownloadsMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Ties {@see \App\Service\Download\DownloadCompletionPoller::poll()} to a fixed interval (issue
 * #685) via {@see PollDownloadsMessage}, consumed off the `scheduler_downloads_poll` transport
 * (see config/packages/messenger.yaml and native/supervisor/messenger-consumer.js). Named
 * "downloads_poll" rather than "default" on purpose: this is not a general-purpose app scheduler,
 * only the wiring for this one poller — see the issue's "Чего не делается".
 *
 * 5 minutes is a bound picked for this poller specifically, not a project-wide convention: a
 * finished torrent sits unlinked for at most this long while the app is open (the app-startup run
 * in native/supervisor/downloads-poll.js covers the "app was closed" case), which is short enough
 * to feel responsive without polling qBittorrent's WebUI more than necessary.
 */
#[AsSchedule('downloads_poll')]
final class DownloadsPollSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::every('5 minutes', new PollDownloadsMessage()));
    }
}
