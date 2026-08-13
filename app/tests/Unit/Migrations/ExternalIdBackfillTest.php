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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Exercises Version20260801000001's backfill INSERT directly against a real SQLite connection,
 * following the same pattern as CatalogSchemaTest: the old metadata['external_id'][pluginId]
 * JSON model never enforced UNIQUE(plugin_id, external_id) across anime rows, so a straight
 * INSERT into the newly-indexed anime_external_id table would abort the whole migration (and
 * the user's upgrade) on any pre-existing duplicate. This proves the OR IGNORE backfill survives
 * that case instead of crashing, and picks a single deterministic winner.
 */
final class ExternalIdBackfillTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement('CREATE TABLE anime (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            metadata CLOB DEFAULT NULL
        )');

        $this->connection->executeStatement('CREATE TABLE anime_external_id (
            anime_id INTEGER NOT NULL,
            plugin_id VARCHAR(64) NOT NULL,
            external_id VARCHAR(255) NOT NULL,
            PRIMARY KEY (anime_id, plugin_id)
        )');
        $this->connection->executeStatement('CREATE UNIQUE INDEX UNIQ_ANIME_EXTERNAL_ID_PLUGIN_EXTERNAL ON anime_external_id (plugin_id, external_id)');
    }

    public function testBackfillSurvivesAPreExistingCrossAnimeDuplicateInsteadOfAbortingTheMigration(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO anime (metadata) VALUES (json('{\"external_id\":{\"mal\":\"1\"}}'))",
        );
        $this->connection->executeStatement(
            "INSERT INTO anime (metadata) VALUES (json('{\"external_id\":{\"mal\":\"1\"}}'))",
        );

        $this->runBackfill();

        $rows = $this->connection->fetchAllAssociative('SELECT anime_id, plugin_id, external_id FROM anime_external_id');
        self::assertCount(1, $rows, 'the conflicting duplicate is silently dropped, not left duplicated or aborting the migration');
        self::assertSame(1, $rows[0]['anime_id'], 'the first-inserted anime deterministically keeps the mapping');
    }

    public function testBackfillMigratesEveryDistinctPluginExternalIdPair(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO anime (metadata) VALUES (json('{\"external_id\":{\"mal\":\"1\",\"anidb\":\"7\"}}'))",
        );

        $this->runBackfill();

        $rows = $this->connection->fetchAllAssociative('SELECT plugin_id, external_id FROM anime_external_id ORDER BY plugin_id');
        self::assertSame(
            [['plugin_id' => 'anidb', 'external_id' => '7'], ['plugin_id' => 'mal', 'external_id' => '1']],
            $rows,
        );
    }

    private function runBackfill(): void
    {
        $this->connection->executeStatement("INSERT OR IGNORE INTO anime_external_id (anime_id, plugin_id, external_id)
            SELECT a.id, je.key, je.value
            FROM (
                SELECT id, metadata FROM anime
                WHERE metadata IS NOT NULL AND json_extract(metadata, '$.external_id') IS NOT NULL
            ) a, json_each(a.metadata, '$.external_id') AS je");
    }
}
