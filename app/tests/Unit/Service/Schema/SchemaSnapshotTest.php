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

namespace App\Tests\Unit\Service\Schema;

use App\Service\Schema\SchemaSnapshot;
use PHPUnit\Framework\TestCase;

final class SchemaSnapshotTest extends TestCase
{
    private const DROPPED_TRIGGER = "trigger\tanime_fts_ad_anime_name\tCREATE TRIGGER anime_fts_ad_anime_name AFTER DELETE ON anime_name BEGIN DELETE FROM anime_fts WHERE rowid = -old.id; END";

    public function testFiltersOnlyShadowTablesNotFtsTriggers(): void
    {
        $rows = SchemaSnapshot::fromMasterRows([
            ['type' => 'table', 'name' => 'anime_fts', 'sql' => 'CREATE VIRTUAL TABLE anime_fts USING fts5(a)'],
            ['type' => 'table', 'name' => 'anime_fts_data', 'sql' => 'CREATE TABLE anime_fts_data(id)'],
            ['type' => 'table', 'name' => 'anime_fts_config', 'sql' => 'CREATE TABLE anime_fts_config(k)'],
            ['type' => 'table', 'name' => 'sqlite_sequence', 'sql' => 'CREATE TABLE sqlite_sequence(name,seq)'],
            ['type' => 'index', 'name' => 'sqlite_autoindex_x_1', 'sql' => null],
            ['type' => 'trigger', 'name' => 'anime_fts_ai_anime', 'sql' => 'CREATE TRIGGER anime_fts_ai_anime AFTER INSERT ON anime BEGIN SELECT 1; END'],
        ]);

        $this->assertSame([
            "table\tanime_fts\tCREATE VIRTUAL TABLE anime_fts USING fts5(a)",
            "trigger\tanime_fts_ai_anime\tCREATE TRIGGER anime_fts_ai_anime AFTER INSERT ON anime BEGIN SELECT 1; END",
        ], $rows);
    }

    public function testReformattingDoesNotChangeTheSnapshot(): void
    {
        $compact = [['type' => 'trigger', 'name' => 't', 'sql' => 'CREATE TRIGGER t AFTER DELETE ON a BEGIN DELETE FROM b; END']];
        $formatted = [['type' => 'trigger', 'name' => 't', 'sql' => "CREATE TRIGGER t\n    AFTER DELETE ON a\n\tBEGIN\n        DELETE FROM b;\n    END  "]];

        $this->assertSame(SchemaSnapshot::fromMasterRows($compact), SchemaSnapshot::fromMasterRows($formatted));
    }

    public function testLostTriggerYieldsExactlyOneRemovedLine(): void
    {
        $golden = SchemaSnapshot::parse((string) file_get_contents(__DIR__.'/../../../../migrations/schema.golden.tsv'));
        $this->assertContains(self::DROPPED_TRIGGER, $golden);

        $actual = array_values(array_diff($golden, [self::DROPPED_TRIGGER]));

        $this->assertSame(['< '.self::DROPPED_TRIGGER], SchemaSnapshot::diff($golden, $actual));
        $this->assertSame([], SchemaSnapshot::diff($golden, $golden));
    }

    public function testGoldenFileComposition(): void
    {
        $golden = SchemaSnapshot::parse((string) file_get_contents(__DIR__.'/../../../../migrations/schema.golden.tsv'));
        $keys = array_map(static fn (string $row): string => implode("\t", \array_slice(explode("\t", $row), 0, 2)), $golden);

        foreach (['ai', 'au', 'ad'] as $op) {
            $this->assertContains("trigger\tanime_fts_{$op}_anime", $keys);
            $this->assertContains("trigger\tanime_fts_{$op}_anime_name", $keys);
        }
        $this->assertContains("table\tanime_fts", $keys);
        foreach (['data', 'idx', 'content', 'docsize', 'config'] as $shadow) {
            $this->assertNotContains("table\tanime_fts_{$shadow}", $keys);
        }
        $this->assertNotContains("table\tsqlite_sequence", $keys);
        foreach ($golden as $row) {
            $this->assertCount(3, explode("\t", $row));
            $this->assertStringNotContainsString('sqlite_autoindex_', $row);
        }
        $sorted = $golden;
        sort($sorted, \SORT_STRING);
        $this->assertSame($sorted, $golden);
    }
}
