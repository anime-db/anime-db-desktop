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

use App\Service\Schema\MappingSchemaComparator;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\TestCase;

/**
 * The false positive this comparator filters out is narrow on purpose, so the tests below pin both
 * halves of it: the shape that must be swallowed, and every neighbouring shape that must not be.
 */
final class MappingSchemaComparatorTest extends TestCase
{
    /**
     * @param array<string, bool>    $databaseAutoIncrement
     * @param array<string, bool>    $mappingAutoIncrement
     * @param non-empty-list<string> $primaryKey
     *
     * @return list<string>
     */
    private function divergences(
        array $primaryKey,
        array $databaseAutoIncrement,
        array $mappingAutoIncrement,
        ?int $mappingLength = null,
    ): array {
        $build = static function (array $autoIncrement, ?int $length) use ($primaryKey): Schema {
            $table = new Table('pairing');
            $table->addColumn('anime_id', 'integer', ['autoincrement' => $autoIncrement['anime_id'] ?? false]);
            $table->addColumn('code', 'string', ['length' => $length ?? 32]);
            $table->setPrimaryKey($primaryKey);

            return new Schema([$table]);
        };

        $database = $build($databaseAutoIncrement, null);
        $mapping = $build($mappingAutoIncrement, $mappingLength);

        $comparator = new Comparator(new SQLitePlatform());

        return MappingSchemaComparator::divergences($comparator->compareSchemas($database, $mapping));
    }

    public function testCompositeKeyAutoIncrementReportedOnlyByIntrospectionIsFiltered(): void
    {
        self::assertSame([], $this->divergences(
            ['anime_id', 'code'],
            ['anime_id' => true],
            ['anime_id' => false],
        ));
    }

    public function testAutoIncrementDifferenceOnASingleColumnKeyIsReported(): void
    {
        $divergences = $this->divergences(['anime_id'], ['anime_id' => true], ['anime_id' => false]);

        self::assertCount(1, $divergences);
        self::assertStringContainsString('autoincrement', $divergences[0]);
    }

    public function testAutoIncrementDifferenceInTheOppositeDirectionIsReported(): void
    {
        $divergences = $this->divergences(
            ['anime_id', 'code'],
            ['anime_id' => false],
            ['anime_id' => true],
        );

        self::assertCount(1, $divergences);
        self::assertStringContainsString('autoincrement', $divergences[0]);
    }

    public function testAutoIncrementDifferenceCombinedWithAnotherChangeIsReported(): void
    {
        $divergences = $this->divergences(
            ['anime_id', 'code'],
            ['anime_id' => true],
            ['anime_id' => false],
            mappingLength: 16,
        );

        self::assertNotSame([], $divergences);
        self::assertStringContainsString('length', implode("\n", $divergences));
    }

    public function testMigrationsBookkeepingTableIsNotReportedAsUnmapped(): void
    {
        $bookkeeping = new Table('doctrine_migration_versions');
        $bookkeeping->addColumn('version', 'string', ['length' => 191]);
        $bookkeeping->setPrimaryKey(['version']);

        $comparator = new Comparator(new SQLitePlatform());

        self::assertSame([], MappingSchemaComparator::divergences(
            $comparator->compareSchemas(new Schema([$bookkeeping]), new Schema()),
        ));
    }

    public function testATableMissingFromTheMappingIsReported(): void
    {
        $orphan = new Table('left_over');
        $orphan->addColumn('id', 'integer', ['autoincrement' => true]);
        $orphan->setPrimaryKey(['id']);

        $comparator = new Comparator(new SQLitePlatform());
        $divergences = MappingSchemaComparator::divergences(
            $comparator->compareSchemas(new Schema([$orphan]), new Schema()),
        );

        self::assertCount(1, $divergences);
        self::assertStringContainsString('left_over', $divergences[0]);
        self::assertStringContainsString('missing from the mapping', $divergences[0]);
    }

    public function testAnIndexMissingFromTheMappingIsReported(): void
    {
        $withIndex = new Table('pairing');
        $withIndex->addColumn('anime_id', 'integer');
        $withIndex->addColumn('code', 'string', ['length' => 32]);
        $withIndex->setPrimaryKey(['anime_id', 'code']);
        $withIndex->addIndex(['code'], 'IDX_PAIRING_CODE');

        $withoutIndex = new Table('pairing');
        $withoutIndex->addColumn('anime_id', 'integer');
        $withoutIndex->addColumn('code', 'string', ['length' => 32]);
        $withoutIndex->setPrimaryKey(['anime_id', 'code']);

        $comparator = new Comparator(new SQLitePlatform());
        $divergences = MappingSchemaComparator::divergences(
            $comparator->compareSchemas(new Schema([$withIndex]), new Schema([$withoutIndex])),
        );

        self::assertCount(1, $divergences);
        self::assertStringContainsString('IDX_PAIRING_CODE', $divergences[0]);
        self::assertStringContainsString('missing from the mapping', $divergences[0]);
    }
}
