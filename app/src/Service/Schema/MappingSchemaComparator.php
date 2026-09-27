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

namespace App\Service\Schema;

use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;

/**
 * Turns a Doctrine SchemaDiff — introspected database on one side, schema derived from the entity
 * mapping on the other — into a list of human-readable divergences, dropping the two differences
 * that are known not to be real.
 *
 * `doctrine:schema:validate` cannot be used for this. On SQLite its introspection misreports
 * `autoincrement` on a composite primary key: SQLiteSchemaManager::fetchTableColumns() collects
 * primary-key columns but keeps only those whose declared type is exactly INTEGER, and only then
 * asks whether the collected list is a single column. A composite key holding exactly one INTEGER
 * column therefore reads as a single-column integer key, i.e. as a rowid alias with
 * autoincrement. Four tables in this schema are shaped that way (`anime_genres`, `anime_themes`,
 * `anime_sync_state`, `anime_external_id`: INTEGER plus VARCHAR), and the mapping is right to say
 * there is no autoincrement on an association column. The divergence is closable from neither
 * side, and ComparatorConfig has no switch for it, so it is filtered out here — but only under the
 * exact conditions that make it a false positive, never as a blanket "ignore autoincrement".
 */
final class MappingSchemaComparator
{
    /**
     * Bookkeeping table of the migrations bundle. It is created by the bundle rather than by a
     * migration or an entity, so it is absent from the mapping by design.
     */
    private const UNMAPPED_TABLES = ['doctrine_migration_versions'];

    /**
     * @return list<string> one line per divergence, empty when the mapping matches the schema
     */
    public static function divergences(SchemaDiff $diff): array
    {
        $divergences = [];

        foreach ($diff->getCreatedTables() as $table) {
            $divergences[] = \sprintf('table %s: in the mapping, missing from the schema', $table->getName());
        }

        foreach ($diff->getDroppedTables() as $table) {
            if (\in_array($table->getName(), self::UNMAPPED_TABLES, true)) {
                continue;
            }

            $divergences[] = \sprintf('table %s: in the schema, missing from the mapping', $table->getName());
        }

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $table = $tableDiff->getOldTable();
            $name = $table->getName();

            foreach ($tableDiff->getChangedColumns() as $columnDiff) {
                if (self::isSqliteCompositeKeyAutoIncrementFalsePositive($columnDiff, $table)) {
                    continue;
                }

                $divergences[] = \sprintf(
                    'column %s.%s differs: %s',
                    $name,
                    $columnDiff->getOldColumn()->getName(),
                    implode(', ', self::changedProperties($columnDiff)),
                );
            }

            foreach ($tableDiff->getAddedColumns() as $column) {
                $divergences[] = \sprintf('column %s.%s: in the mapping, missing from the schema', $name, $column->getName());
            }

            foreach ($tableDiff->getDroppedColumns() as $column) {
                $divergences[] = \sprintf('column %s.%s: in the schema, missing from the mapping', $name, $column->getName());
            }

            foreach ($tableDiff->getRenamedColumns() as $oldName => $column) {
                $divergences[] = \sprintf('column %s.%s renamed to %s', $name, $oldName, $column->getName());
            }

            foreach ($tableDiff->getAddedIndexes() as $index) {
                $divergences[] = \sprintf('index %s on %s: in the mapping, missing from the schema', $index->getName(), $name);
            }

            foreach ($tableDiff->getDroppedIndexes() as $index) {
                $divergences[] = \sprintf('index %s on %s: in the schema, missing from the mapping', $index->getName(), $name);
            }

            foreach ($tableDiff->getModifiedIndexes() as $index) {
                $divergences[] = \sprintf('index %s on %s differs between schema and mapping', $index->getName(), $name);
            }

            foreach ($tableDiff->getRenamedIndexes() as $oldName => $index) {
                $divergences[] = \sprintf('index %s on %s renamed to %s', $oldName, $name, $index->getName());
            }

            foreach ($tableDiff->getAddedForeignKeys() as $foreignKey) {
                $divergences[] = \sprintf('foreign key %s on %s: in the mapping, missing from the schema', $foreignKey->getName(), $name);
            }

            foreach ($tableDiff->getDroppedForeignKeys() as $foreignKey) {
                $divergences[] = \sprintf('foreign key %s on %s: in the schema, missing from the mapping', $foreignKey->getName(), $name);
            }

            foreach ($tableDiff->getModifiedForeignKeys() as $foreignKey) {
                $divergences[] = \sprintf('foreign key %s on %s differs between schema and mapping', $foreignKey->getName(), $name);
            }
        }

        return $divergences;
    }

    /**
     * True only for the DBAL introspection defect described in the class docblock: autoincrement is
     * the single differing property, the column belongs to a composite primary key, and it is the
     * introspected side that claims autoincrement. Any other autoincrement difference is a real
     * divergence and must be reported.
     */
    private static function isSqliteCompositeKeyAutoIncrementFalsePositive(ColumnDiff $columnDiff, Table $introspected): bool
    {
        if ($columnDiff->countChangedProperties() !== 1 || !$columnDiff->hasAutoIncrementChanged()) {
            return false;
        }

        if (!$columnDiff->getOldColumn()->getAutoincrement() || $columnDiff->getNewColumn()->getAutoincrement()) {
            return false;
        }

        $primaryKey = $introspected->getPrimaryKey();
        if ($primaryKey === null) {
            return false;
        }

        $primaryKeyColumns = $primaryKey->getColumns();

        return \count($primaryKeyColumns) > 1
            && \in_array($columnDiff->getOldColumn()->getName(), $primaryKeyColumns, true);
    }

    /**
     * @return list<string>
     */
    private static function changedProperties(ColumnDiff $columnDiff): array
    {
        $checks = [
            'type' => $columnDiff->hasTypeChanged(),
            'length' => $columnDiff->hasLengthChanged(),
            'precision' => $columnDiff->hasPrecisionChanged(),
            'scale' => $columnDiff->hasScaleChanged(),
            'unsigned' => $columnDiff->hasUnsignedChanged(),
            'fixed' => $columnDiff->hasFixedChanged(),
            'notnull' => $columnDiff->hasNotNullChanged(),
            'default' => $columnDiff->hasDefaultChanged(),
            'autoincrement' => $columnDiff->hasAutoIncrementChanged(),
            'comment' => $columnDiff->hasCommentChanged(),
            'platform options' => $columnDiff->hasPlatformOptionsChanged(),
        ];

        return array_keys(array_filter($checks));
    }
}
