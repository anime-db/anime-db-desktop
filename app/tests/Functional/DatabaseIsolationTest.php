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

/**
 * Guards the isolation promised by `.env.test`: the functional suite must run against
 * `var/test/data.db`, never the developer's `data/data.db`.
 */
final class DatabaseIsolationTest extends FunctionalTestCase
{
    public function testKernelUsesDedicatedTestDatabaseFiles(): void
    {
        $connection = $this->entityManager()->getConnection();
        $params = $connection->getParams();

        self::assertSame(self::projectDir().'/var/test/data.db', $params['path'] ?? null);
        self::assertFileExists(self::projectDir().'/var/test/data.db');
    }

    public function testRequestsDoNotTouchTheWorkingDatabase(): void
    {
        $working = self::projectDir().'/../data/data.db';
        $existedBefore = file_exists($working);
        $mtimeBefore = $existedBefore ? filemtime($working) : null;
        clearstatcache();

        $this->client->request('GET', '/settings');

        clearstatcache();
        self::assertSame($existedBefore, file_exists($working));
        self::assertSame($mtimeBefore, $existedBefore ? filemtime($working) : null);
    }
}
