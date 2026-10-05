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

use App\Message\SyncPullTickMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Ties the periodic sync pull (issue #870) to an hourly {@see SyncPullTickMessage}, consumed off
 * the `scheduler_sync_pull` transport (see config/packages/messenger.yaml and
 * native/supervisor/messenger-consumer.js).
 *
 * The tick is hourly, while a plugin is actually pulled at most once per
 * {@see \App\Service\Sync\SyncPullGate::MAX_AGE_SECONDS}: the schedule timer restarts with every
 * consumer restart, so a 6-hour interval would almost never fire on a desktop app. No `from` and
 * no `stateful()` — missed ticks need no catch-up, the gate is idempotent.
 */
#[AsSchedule('sync_pull')]
final class SyncPullSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::every('1 hour', new SyncPullTickMessage()));
    }
}
