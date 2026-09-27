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
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;

/**
 * Builds a schema by running every migration under app/migrations against a connection, instead
 * of hand-copying CREATE TABLE/CREATE TRIGGER statements into a test's setUp() — the two
 * inevitably drift apart (issue #762). Migration classes live outside the PSR-4 autoload map (see
 * phpstan.dist.neon's scanDirectories), so each file is require_once'd before being instantiated,
 * the same way the single-migration tests under tests/Unit/Migrations already do.
 *
 * Mirrors the parts of Doctrine\Migrations\Version\DbalExecutor::executeMigration() that change
 * observable behaviour: it honours isTransactional() (several migrations toggle
 * "PRAGMA foreign_keys", which SQLite silently ignores inside a transaction) and calls
 * preUp()/postUp() around the planned SQL, not just up()'s addSql() calls.
 */
trait RunsMigrations
{
    private function buildSchemaByRunningMigrations(Connection $connection): void
    {
        $files = glob(__DIR__.'/../../migrations/Version*.php');
        self::assertIsArray($files, 'glob() failed to read app/migrations');
        self::assertNotEmpty($files, 'No migration files found under app/migrations');
        sort($files, \SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            require_once $file;

            $class = 'DoctrineMigrations\\'.pathinfo($file, \PATHINFO_FILENAME);
            $migration = new $class($connection, new NullLogger());
            \assert($migration instanceof AbstractMigration, \sprintf('%s from %s is not a Doctrine migration', $class, $file));

            $this->runMigrationUp($connection, $migration);
            $applied[] = $class;
        }

        self::assertSame(
            \count($files),
            \count($applied),
            'Not every migration under app/migrations was applied; the test schema would not match the real one.',
        );
    }

    private function runMigrationUp(Connection $connection, AbstractMigration $migration): void
    {
        $transactional = $migration->isTransactional();
        if ($transactional) {
            $connection->beginTransaction();
        }

        $schema = new Schema();
        $migration->preUp($schema);
        $migration->up($schema);
        $queries = $migration->getSql();
        $migration->freeze();

        foreach ($queries as $query) {
            $connection->executeQuery($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $migration->postUp($schema);

        if ($transactional) {
            $connection->commit();
        }
    }
}
