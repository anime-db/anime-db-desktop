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

namespace App\Tests\Unit\Service\Import\V1;

use App\Service\Import\V1\V1CatalogReader;
use App\Tests\Support\TemporaryDirectories;
use App\Tests\Support\V1DatabaseBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class V1CatalogReaderTest extends TestCase
{
    use TemporaryDirectories;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    /** @return iterable<string, array{string}> */
    public static function linkColumns(): iterable
    {
        yield 'v1 schema (item)' => ['item'];
        yield 'item_id' => ['item_id'];
    }

    #[DataProvider('linkColumns')]
    public function testReadAttachesNamesAndSourcesToTheirRecords(string $linkColumn): void
    {
        $builder = V1DatabaseBuilder::create($this->createTemporaryDirectory('v1-reader-'));
        if ($linkColumn !== 'item') {
            $builder->pdo->exec('DROP TABLE name');
            $builder->pdo->exec('DROP TABLE source');
            $builder->pdo->exec(\sprintf('CREATE TABLE name (id INTEGER PRIMARY KEY AUTOINCREMENT, %s INTEGER DEFAULT NULL, name VARCHAR(256) NOT NULL)', $linkColumn));
            $builder->pdo->exec(\sprintf('CREATE TABLE source (id INTEGER PRIMARY KEY AUTOINCREMENT, %s INTEGER DEFAULT NULL, url VARCHAR(256) NOT NULL)', $linkColumn));
        }
        $first = $builder->item(['name' => 'First']);
        $second = $builder->item(['name' => 'Second']);
        $builder->pdo->exec(\sprintf("INSERT INTO name (%s, name) VALUES (%d, 'Alt one'), (%d, 'Alt two'), (%d, 'Other')", $linkColumn, $first, $first, $second));
        $builder->pdo->exec(\sprintf("INSERT INTO source (%s, url) VALUES (%d, 'http://example.com/1'), (%d, 'http://example.com/2')", $linkColumn, $second, $first));

        $records = (new V1CatalogReader(new NullLogger()))->read($builder->root);

        $this->assertCount(2, $records);
        $this->assertSame(['Alt one', 'Alt two'], $records[0]->names);
        $this->assertSame(['http://example.com/2'], $records[0]->sources);
        $this->assertSame(['Other'], $records[1]->names);
        $this->assertSame(['http://example.com/1'], $records[1]->sources);
    }
}
