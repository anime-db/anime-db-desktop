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

use App\Service\Schema\SchemaSnapshot;
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
 *
 * A runner that silently drops part of the chain (wrong glob, typo'd directory, a migration file
 * under a different naming pattern) is caught by comparing the resulting schema against the
 * committed migrations/schema.golden.tsv (see SchemaSnapshot, SchemaCheckCommand) rather than by
 * counting loop iterations, which can't fail independently of the loop itself.
 */
trait RunsMigrations
{
    /**
     * @param string|null $throughVersion fully-qualified class name (e.g.
     *                                    'DoctrineMigrations\Version20260801000002') of the last
     *                                    migration to run; when given, the golden-snapshot
     *                                    self-check is skipped (the schema is incomplete) and the
     *                                    caller is expected to finish the chain later via
     *                                    {@see finishSchemaByRunningMigrations()}
     *
     * @return list<string> absolute paths of the migration files still to run, in chain order;
     *                      empty when $throughVersion is null, since the whole chain just ran
     */
    private function buildSchemaByRunningMigrations(Connection $connection, ?string $throughVersion = null): array
    {
        $files = $this->migrationFiles();

        foreach ($files as $index => $file) {
            $this->runMigrationFile($connection, $file);

            if ($throughVersion !== null && 'DoctrineMigrations\\'.pathinfo($file, \PATHINFO_FILENAME) === $throughVersion) {
                return \array_slice($files, $index + 1);
            }
        }

        if ($throughVersion !== null) {
            self::fail(\sprintf("Migration '%s' not found among app/migrations files", $throughVersion));
        }

        $this->assertSchemaMatchesGoldenSnapshot($connection);

        return [];
    }

    /**
     * Runs the remainder of the chain returned by a prior {@see buildSchemaByRunningMigrations()}
     * call made with $throughVersion, then performs the golden-snapshot self-check that call
     * skipped.
     *
     * @param list<string> $remainingMigrationFiles
     */
    private function finishSchemaByRunningMigrations(Connection $connection, array $remainingMigrationFiles): void
    {
        foreach ($remainingMigrationFiles as $file) {
            $this->runMigrationFile($connection, $file);
        }

        $this->assertSchemaMatchesGoldenSnapshot($connection);
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = glob(__DIR__.'/../../migrations/Version*.php');
        self::assertIsArray($files, 'glob() failed to read app/migrations');
        self::assertNotEmpty($files, 'No migration files found under app/migrations');
        sort($files, \SORT_STRING);

        return $files;
    }

    private function runMigrationFile(Connection $connection, string $file): void
    {
        require_once $file;

        $class = 'DoctrineMigrations\\'.pathinfo($file, \PATHINFO_FILENAME);
        $migration = new $class($connection, new NullLogger());
        \assert($migration instanceof AbstractMigration, \sprintf('%s from %s is not a Doctrine migration', $class, $file));

        $this->runMigrationUp($connection, $migration);
    }

    private function assertSchemaMatchesGoldenSnapshot(Connection $connection): void
    {
        $masterRows = array_map(
            static fn (array $row): array => [
                'type' => (string) $row['type'],
                'name' => (string) $row['name'],
                'sql' => $row['sql'] === null ? null : (string) $row['sql'],
            ],
            $connection->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master'),
        );
        $actual = SchemaSnapshot::fromMasterRows($masterRows);

        $goldenPath = __DIR__.'/../../migrations/schema.golden.tsv';
        self::assertFileExists($goldenPath, 'Golden schema snapshot not found; run bin/console app:schema:check --write');
        // doctrine_migration_versions is created by the migrations bundle's own bookkeeping, not by
        // any migration in app/migrations, so it's absent here: this trait replays migrations
        // directly instead of going through doctrine:migrations:migrate.
        $golden = array_values(array_filter(
            SchemaSnapshot::parse((string) file_get_contents($goldenPath)),
            static fn (string $row): bool => !str_starts_with($row, "table\tdoctrine_migration_versions\t"),
        ));

        $diff = SchemaSnapshot::diff($golden, $actual);
        self::assertSame(
            [],
            $diff,
            "Schema built by running migrations differs from migrations/schema.golden.tsv (< golden, > migrations):\n".implode("\n", $diff),
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
