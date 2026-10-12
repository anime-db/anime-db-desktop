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

namespace App\Tests\Unit\Twig;

use App\Repository\NotificationRepository;
use App\Twig\NotificationBellExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class NotificationBellExtensionTest extends TestCase
{
    public function testUnreadCountComesFromTheRepository(): void
    {
        $repository = $this->createStub(NotificationRepository::class);
        $repository->method('countUnread')->willReturn(4);

        self::assertSame(4, (new NotificationBellExtension($repository, new NullLogger()))->unreadCount());
    }

    public function testAFailedReadDegradesToZeroAndIsLogged(): void
    {
        $repository = $this->createStub(NotificationRepository::class);
        $repository->method('countUnread')->willThrowException(new \RuntimeException('no table'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        self::assertSame(0, (new NotificationBellExtension($repository, $logger))->unreadCount());
    }
}
