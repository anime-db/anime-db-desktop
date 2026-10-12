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

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * The partial unique index of the notification merge key lives in the migration only (the ORM
 * mapping cannot express it), so a schema built by SchemaTool::createSchema() lacks it. The test
 * harnesses add it with the very predicate of the migration, so NotificationCenter behaves there as
 * in production.
 */
final class NotificationSchemaIndex
{
    public static function create(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE UNIQUE INDEX UNIQ_NOTIFICATION_DEDUP_UNREAD ON notification (dedup_key) WHERE (read_at IS NULL)',
        );
    }
}
