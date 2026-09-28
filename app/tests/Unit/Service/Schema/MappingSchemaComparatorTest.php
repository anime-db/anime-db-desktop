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
 * The false positive this comparator filters out is narrow on purpose, so the tests below pin every
 * condition of it separately: drop any one of them from the production code and a test here fails.
 */
final class MappingSchemaComparatorTest extends TestCase
{
    /**
     * @param callable(Table): void $database
     * @param callable(Table): void $mapping
     *
     * @return list<string>
     */
    private function divergences(callable $database, callable $mapping): array
    {
        $build = static function (callable $shape): Schema {
            $table = new Table('pairing');
            $shape($table);

            return new Schema([$table]);
        };

        return MappingSchemaComparator::divergences(
            (new Comparator(new SQLitePlatform()))->compareSchemas($build($database), $build($mapping)),
        );
    }

    /**
     * `anime_id` carries the autoincrement flag and is the first column of a composite primary key —
     * exactly the shape DBAL's SQLite introspection gets wrong.
     *
     * @param array{autoincrement?: bool, default?: int} $animeIdOptions
     * @param non-empty-list<string>|null                $primaryKey
     */
    private static function compositeKeyTable(array $animeIdOptions = [], ?array $primaryKey = null): callable
    {
        return static function (Table $table) use ($animeIdOptions, $primaryKey): void {
            $table->addColumn('anime_id', 'integer', $animeIdOptions + ['notnull' => true]);
            $table->addColumn('code', 'string', ['length' => 32]);
            $table->setPrimaryKey($primaryKey ?? ['anime_id', 'code']);
        };
    }

    public function testAutoIncrementClaimedOnlyByIntrospectionOnACompositeKeyIsFiltered(): void
    {
        self::assertSame([], $this->divergences(
            self::compositeKeyTable(['autoincrement' => true]),
            self::compositeKeyTable(['autoincrement' => false]),
        ));
    }

    public function testAutoIncrementDifferenceOnASingleColumnKeyIsReported(): void
    {
        $divergences = $this->divergences(
            self::compositeKeyTable(['autoincrement' => true], ['anime_id']),
            self::compositeKeyTable(['autoincrement' => false], ['anime_id']),
        );

        self::assertSame(['column pairing.anime_id differs: autoincrement'], $divergences);
    }

    public function testAutoIncrementDifferenceInTheOppositeDirectionIsReported(): void
    {
        $divergences = $this->divergences(
            self::compositeKeyTable(['autoincrement' => false]),
            self::compositeKeyTable(['autoincrement' => true]),
        );

        self::assertSame(['column pairing.anime_id differs: autoincrement'], $divergences);
    }

    /**
     * Second difference on the *same* column, so the filter cannot be satisfied by
     * countChangedProperties() === 1 any more.
     */
    public function testAutoIncrementCombinedWithAnotherChangeOnTheSameColumnIsReported(): void
    {
        $divergences = $this->divergences(
            self::compositeKeyTable(['autoincrement' => true]),
            self::compositeKeyTable(['autoincrement' => false, 'default' => 0]),
        );

        self::assertCount(1, $divergences);
        self::assertStringContainsString('pairing.anime_id differs:', $divergences[0]);
        self::assertStringContainsString('default', $divergences[0]);
        self::assertStringContainsString('autoincrement', $divergences[0]);
    }

    /** The composite key is there, but the differing column is not part of it. */
    public function testAutoIncrementDifferenceOnAColumnOutsideThePrimaryKeyIsReported(): void
    {
        $outside = static function (bool $autoincrement): callable {
            return static function (Table $table) use ($autoincrement): void {
                $table->addColumn('anime_id', 'integer', ['notnull' => true]);
                $table->addColumn('code', 'string', ['length' => 32]);
                $table->addColumn('counter', 'integer', ['autoincrement' => $autoincrement]);
                $table->setPrimaryKey(['anime_id', 'code']);
            };
        };

        self::assertSame(
            ['column pairing.counter differs: autoincrement'],
            $this->divergences($outside(true), $outside(false)),
        );
    }

    public function testMigrationsBookkeepingTableIsNotReportedAsUnmapped(): void
    {
        self::assertSame([], $this->compareAgainstEmptyMapping('doctrine_migration_versions'));
    }

    public function testATableMissingFromTheMappingIsReported(): void
    {
        self::assertSame(
            ['table left_over: in the schema, missing from the mapping'],
            $this->compareAgainstEmptyMapping('left_over'),
        );
    }

    public function testAnIndexMissingFromTheMappingIsReported(): void
    {
        $divergences = $this->divergences(
            static function (Table $table): void {
                self::compositeKeyTable()($table);
                $table->addIndex(['code'], 'IDX_PAIRING_CODE');
            },
            self::compositeKeyTable(),
        );

        self::assertSame(['index IDX_PAIRING_CODE on pairing: in the schema, missing from the mapping'], $divergences);
    }

    public function testAColumnMissingFromTheMappingIsReported(): void
    {
        $divergences = $this->divergences(
            static function (Table $table): void {
                self::compositeKeyTable()($table);
                $table->addColumn('extra', 'string', ['length' => 8]);
            },
            self::compositeKeyTable(),
        );

        self::assertSame(['column pairing.extra: in the schema, missing from the mapping'], $divergences);
    }

    /**
     * @return list<string>
     */
    private function compareAgainstEmptyMapping(string $tableName): array
    {
        $table = new Table($tableName);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);

        return MappingSchemaComparator::divergences(
            (new Comparator(new SQLitePlatform()))->compareSchemas(new Schema([$table]), new Schema()),
        );
    }
}
