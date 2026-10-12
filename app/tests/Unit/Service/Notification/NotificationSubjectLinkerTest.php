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

namespace App\Tests\Unit\Service\Notification;

use App\Service\Notification\NotificationSubjectLinker;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NotificationSubjectLinkerTest extends TestCase
{
    private function linker(): NotificationSubjectLinker
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE anime (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('INSERT INTO anime (id) VALUES (7)');
        $connection->executeStatement('CREATE TABLE scan_run (id INTEGER PRIMARY KEY, storage_id INTEGER NOT NULL)');
        $connection->executeStatement('INSERT INTO scan_run (id, storage_id) VALUES (12, 3)');
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(static fn (string $route, array $params): string => $route === 'storage_scan_run'
            ? \sprintf('/storage/%d/scans/%d', $params['id'], $params['run'])
            : '/anime/'.$params['id']);

        return new NotificationSubjectLinker($generator, $connection);
    }

    public function testExistingSubjectHasALink(): void
    {
        $this->assertSame('/anime/7', $this->linker()->urlFor('anime', '7'));
    }

    public function testNoSubjectVanishedSubjectAndUnknownTypeHaveNoLink(): void
    {
        $linker = $this->linker();

        $this->assertNull($linker->urlFor(null, null));
        $this->assertNull($linker->urlFor('anime', '8'));
        $this->assertNull($linker->urlFor('unknown', '7'));
        $this->assertNull($linker->urlFor('anime', 'x'));
    }

    public function testScanRunSubjectLinksToTheRunPageUnlessTheRunIsGone(): void
    {
        $linker = $this->linker();

        $this->assertSame('/storage/3/scans/12', $linker->urlFor('storage_scan_run', '3:12'));
        $this->assertNull($linker->urlFor('storage_scan_run', '3:13'));
        $this->assertNull($linker->urlFor('storage_scan_run', '4:12'));
        $this->assertNull($linker->urlFor('storage_scan_run', 'x'));
        $this->assertNull($linker->urlFor('storage_scan_run', null));
    }
}
