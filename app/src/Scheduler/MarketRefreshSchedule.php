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

use App\Message\MarketRefreshTickMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Ties a periodic market snapshot refresh (issue #448) to a fixed interval, by the same pattern
 * as {@see DownloadsPollSchedule}: a {@see MarketRefreshTickMessage} tick, consumed off the
 * `scheduler_market_refresh` transport (see config/packages/messenger.yaml and
 * native/supervisor/messenger-consumer.js) by {@see \App\MessageHandler\MarketRefreshTickMessageHandler},
 * which decides on every tick whether the cached snapshot is actually stale enough to warrant
 * calling {@see \App\Service\Market\MarketRefreshService::refresh()} — this provider itself knows
 * nothing about staleness, only the interval.
 *
 * 1 hour, not 24 hours: the desktop app rarely stays open for a full day uninterrupted, and
 * without `stateful()` (deliberately not enabled — see the issue's "Чего не делается") the
 * recurring timer resets on every consumer restart (crash, plugin install/update). An hourly tick
 * plus the handler's own 24-hour staleness gate together give "refreshed at least once a day
 * while the app is open" without ever needing to catch up on missed ticks.
 */
#[AsSchedule('market_refresh')]
final class MarketRefreshSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::every('1 hour', new MarketRefreshTickMessage()));
    }
}
