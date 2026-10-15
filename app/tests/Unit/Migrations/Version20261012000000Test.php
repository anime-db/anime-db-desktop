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
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use DoctrineMigrations\Version20261012000000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/../../../migrations/Version20261012000000.php';

/** Runs Version20261012000000 (anime_source.normalized_url and app_meta, issue #1056) against a populated SQLite database. */
final class Version20261012000000Test extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE anime_source (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            url VARCHAR(512) NOT NULL
        )');
    }

    public function testUpFillsNormalizedUrlOfExistingRowsAndKeepsTheUrl(): void
    {
        $this->connection->executeStatement("INSERT INTO anime_source (anime_id, url) VALUES (1, 'http://www.Example.org/a/?y=2&x=1')");
        $this->connection->executeStatement("INSERT INTO anime_source (anime_id, url) VALUES (1, 'https://ru.wikipedia.org/wiki/%d0%9c')");

        $this->migrate();

        $this->assertSame(
            [
                ['url' => 'http://www.Example.org/a/?y=2&x=1', 'normalized_url' => 'https://example.org/a?x=1&y=2'],
                ['url' => 'https://ru.wikipedia.org/wiki/%d0%9c', 'normalized_url' => 'https://ru.wikipedia.org/wiki/%D0%9C'],
            ],
            $this->connection->fetchAllAssociative('SELECT url, normalized_url FROM anime_source ORDER BY id'),
        );
        $this->assertSame(
            1,
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'IDX_ANIME_SOURCE_NORMALIZED_URL'"),
        );
    }

    public function testUpCreatesAppMetaKeyValueTable(): void
    {
        $this->migrate();

        $this->connection->executeStatement("INSERT INTO app_meta (\"key\", value) VALUES ('k', 'v')");
        $this->assertSame('v', $this->connection->fetchOne("SELECT value FROM app_meta WHERE \"key\" = 'k'"));
        $this->expectException(\Throwable::class);
        $this->connection->executeStatement("INSERT INTO app_meta (\"key\", value) VALUES ('k', 'w')");
    }

    private function migrate(): void
    {
        $migration = new Version20261012000000($this->connection, new NullLogger());
        $schema = new Schema();

        $migration->up($schema);
        foreach ($migration->getSql() as $query) {
            /* @var Query $query */
            $this->connection->executeQuery($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $migration->freeze();
        $migration->postUp($schema);
    }
}
