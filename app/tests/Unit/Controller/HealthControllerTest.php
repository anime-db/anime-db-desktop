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

namespace App\Tests\Unit\Controller;

use App\Controller\HealthController;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testHealthReturnsOkStatus(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn('1');

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $controller = new HealthController($conn);
        $response = $controller->health();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testHealthResponseContainsOkStatus(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn('1');

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $controller = new HealthController($conn);
        $response = $controller->health();

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('ok', $data['status']);
    }

    public function testHealthResponseContainsSqliteJsonResult(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn('1');

        $conn = $this->createStub(Connection::class);
        $conn->method('executeQuery')->willReturn($result);

        $controller = new HealthController($conn);
        $response = $controller->health();

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('1', $data['sqlite_json']);
    }

    public function testHealthExecutesJsonExtractQuery(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn('1');

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('json_extract'))
            ->willReturn($result);

        $controller = new HealthController($conn);
        $controller->health();
    }
}
