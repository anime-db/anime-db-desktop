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

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Guards the isolation promised by `.env.test`: the functional suite must run against
 * `var/test/data.db`, never the developer's `data/data.db`.
 */
final class DatabaseIsolationTest extends FunctionalTestCase
{
    /** @var array<string, string|null> */
    private static array $workingBefore = [];

    public static function setUpBeforeClass(): void
    {
        // Captured before any test of this class boots the kernel or creates a schema.
        self::$workingBefore = self::workingFilesState();
    }

    public function testKernelUsesDedicatedTestDatabaseFiles(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        foreach (['default' => 'data.db', 'queue' => 'queue.db'] as $connection => $file) {
            $params = $doctrine->getConnection($connection);
            self::assertInstanceOf(Connection::class, $params);
            $params = $params->getParams();

            self::assertSame(self::projectDir().'/var/test/'.$file, $params['path'] ?? null, $connection);
        }
        self::assertFileExists(self::projectDir().'/var/test/data.db');
        self::assertFileExists(self::projectDir().'/var/test/queue.db');
    }

    public function testRequestsDoNotTouchTheWorkingDatabase(): void
    {
        $this->client->request('GET', '/settings');

        self::assertSame(self::$workingBefore, self::workingFilesState());
    }

    /**
     * @return array<string, string|null> file => size and content hash, null when absent
     */
    private static function workingFilesState(): array
    {
        $state = [];
        foreach (['data.db', 'queue.db'] as $file) {
            $path = self::projectDir().'/../data/'.$file;
            $state[$file] = is_file($path) ? filesize($path).':'.md5_file($path) : null;
        }

        return $state;
    }
}
