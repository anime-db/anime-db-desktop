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

namespace App\Tests\Unit\Migrations;

use App\Service\Migration\MigrationDataIntegrityChecker;
use App\Tests\Support\RunsMigrations;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Proves RunsMigrations::runMigrationUp() honours isTransactional() (issue #803). Version20260801000003
 * runs outside a transaction because "PRAGMA foreign_keys" is a no-op inside one, and it toggles the
 * pragma OFF for the duration of an `anime` table rebuild so the implicit DELETE performed by DROP
 * TABLE does not cascade into child rows. Version20260812000000 does the same toggle around its own
 * `anime` rebuild, but only in down(), which this runner never calls, so that migration stays out of
 * scope here.
 *
 * CatalogSchemaTest and AnimeFtsSchemaTest replay the whole migration chain over an empty
 * database, so this branch is never observable there: with no child rows to begin with, a stray
 * cascade has nothing to destroy. This test instead builds the schema in two stages, seeding the
 * same fixture App\Command\MigrationDataIntegrityCheckCommand uses right before
 * Version20260801000003, so the rebuild has real child rows that a wrongly-transactional run would
 * lose.
 */
final class MigrationRunnerTransactionalityTest extends TestCase
{
    use RunsMigrations;

    /** Last migration before Version20260801000003, the `anime` table rebuild. */
    private const AFTER_EARLY_TABLES_VERSION = 'DoctrineMigrations\Version20260801000002';

    public function testAnimeRebuildPreservesChildRowsWhenItsMigrationRunsOutsideATransaction(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        $remainingMigrationFiles = $this->buildSchemaByRunningMigrations($connection, self::AFTER_EARLY_TABLES_VERSION);

        $pdo = $connection->getNativeConnection();
        \assert($pdo instanceof \PDO);

        $checker = new MigrationDataIntegrityChecker();
        $fixture = $checker->insertEarlyFixture($pdo);

        $this->finishSchemaByRunningMigrations($connection, $remainingMigrationFiles);

        $violations = $checker->verifyCounts($pdo, $fixture['counts']);
        $this->assertSame(
            [],
            $violations,
            "Child rows were lost across the anime rebuild in Version20260801000003:\n".implode("\n", $violations),
        );
    }
}
