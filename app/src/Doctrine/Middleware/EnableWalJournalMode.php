<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Enables SQLite's WAL journal mode on connect. Scoped to the `queue` connection (data/queue.db,
 * see issue #94): that file is polled every 50ms by WsController::connect() (via
 * WsPublisher::since(), issue #146) inside a long-lived streaming HTTP response while the
 * Messenger transport (issue #97) and job locks (issue #98) write to it from other
 * requests/processes — WAL reduces lock contention between readers and writers compared to the
 * default rollback-journal mode.
 */
#[AsMiddleware(connections: ['queue'])]
final class EnableWalJournalMode implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): Connection {
                $connection = parent::connect($params);

                $connection->exec('PRAGMA journal_mode=WAL');

                return $connection;
            }
        };
    }
}
