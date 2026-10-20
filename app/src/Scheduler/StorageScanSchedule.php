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

use App\Message\StorageScanTickMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Ties the background storage scan (issue #1117) to an hourly {@see StorageScanTickMessage},
 * consumed off the `scheduler_storage_scan` transport (see config/packages/messenger.yaml and
 * native/supervisor/messenger-consumer.js).
 *
 * Unlike the sibling schedules the first tick comes 5 minutes after the consumer starts: a
 * desktop session can be shorter than an hour and nothing else triggers a scan at startup. No
 * `stateful()` — missed ticks need no catch-up, the due-rule in the handler is idempotent.
 */
#[AsSchedule('storage_scan')]
final class StorageScanSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::every('1 hour', new StorageScanTickMessage(), from: '+5 minutes'));
    }
}
