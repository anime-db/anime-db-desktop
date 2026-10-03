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

use App\Message\MarketRefreshTickMessage;
use App\Scheduler\MarketRefreshSchedule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

final class MarketRefreshScheduleTest extends TestCase
{
    public function testTheScheduleRecursMarketRefreshTickMessageEveryHour(): void
    {
        $recurringMessages = (new MarketRefreshSchedule())->getSchedule()->getRecurringMessages();

        $this->assertCount(1, $recurringMessages);
        $recurringMessage = $recurringMessages[0];

        $this->assertInstanceOf(PeriodicalTrigger::class, $recurringMessage->getTrigger());
        $this->assertSame('every 1 hour', (string) $recurringMessage->getTrigger());

        $context = new MessageContext('market_refresh', $recurringMessage->getId(), $recurringMessage->getTrigger(), new \DateTimeImmutable());
        $messages = [];
        foreach ($recurringMessage->getProvider()->getMessages($context) as $message) {
            $messages[] = $message;
        }

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(MarketRefreshTickMessage::class, $messages[0]);
    }
}
