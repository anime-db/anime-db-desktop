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

namespace App\Tests\Unit\Scheduler;

use App\Message\StorageScanTickMessage;
use App\Scheduler\StorageScanSchedule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class StorageScanScheduleTest extends TestCase
{
    public function testTheScheduleRecursStorageScanTickMessageEveryHourStartingInFiveMinutes(): void
    {
        $before = new \DateTimeImmutable();
        $schedule = (new StorageScanSchedule())->getSchedule();
        $recurringMessages = $schedule->getRecurringMessages();

        $this->assertCount(1, $recurringMessages);
        $recurringMessage = $recurringMessages[0];

        $trigger = $recurringMessage->getTrigger();
        $this->assertInstanceOf(PeriodicalTrigger::class, $trigger);
        $this->assertSame('every 1 hour', (string) $trigger);
        $this->assertNull($schedule->getState());

        $first = $trigger->getNextRunDate($before->modify('-1 day'));
        $this->assertNotNull($first);
        $delta = $first->getTimestamp() - $before->getTimestamp();
        $this->assertGreaterThanOrEqual(5 * 60 - 5, $delta);
        $this->assertLessThanOrEqual(5 * 60 + 5, $delta);

        $context = new MessageContext('storage_scan', $recurringMessage->getId(), $trigger, new \DateTimeImmutable());
        $messages = [];
        foreach ($recurringMessage->getProvider()->getMessages($context) as $message) {
            $messages[] = $message;
        }

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(StorageScanTickMessage::class, $messages[0]);
    }
}
