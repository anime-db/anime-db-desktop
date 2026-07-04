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

namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Events;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * SQLite ignores foreign key actions (ON DELETE RESTRICT/CASCADE/SET NULL) unless
 * "PRAGMA foreign_keys = ON" is set on every new connection - it is a per-connection
 * setting, not a database file setting. Without this, the anime_studios.studio_id
 * RESTRICT constraint (see issue #48, bug B-20) would silently do nothing.
 */
#[AsDoctrineListener(event: Events::postConnect)]
final class SqliteForeignKeysListener
{
    public function postConnect(ConnectionEventArgs $args): void
    {
        $connection = $args->getConnection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }
    }
}
