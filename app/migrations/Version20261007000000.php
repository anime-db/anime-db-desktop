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

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes storage.path nullable (issue #957): `external-r` has an optional path and `video` has none,
 * so a storage of those types can legitimately exist without one. Existing rows keep their paths.
 *
 * SQLite cannot drop NOT NULL in place — full table rebuild. `anime` and `downloads` reference
 * storage (ON DELETE SET NULL), so foreign keys are switched off around the rebuild so the implicit
 * DELETE of DROP TABLE does not null their references (see gotchas.md).
 */
final class Version20261007000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make storage.path nullable (issue #957)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->rebuild('VARCHAR(1024) DEFAULT NULL', 'path');
    }

    public function down(Schema $schema): void
    {
        // Storages without a path get an empty string: the NOT NULL column cannot hold NULL.
        $this->rebuild('VARCHAR(1024) NOT NULL', "COALESCE(path, '')");
    }

    public function postUp(Schema $schema): void
    {
        $this->assertNoForeignKeyViolations();
    }

    public function postDown(Schema $schema): void
    {
        $this->assertNoForeignKeyViolations();
    }

    private function rebuild(string $pathDefinition, string $pathExpression): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TABLE storage__new (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(256) NOT NULL,
            type VARCHAR(16) NOT NULL CHECK (type IN (\'folder\', \'external\', \'external-r\', \'video\')),
            path '.$pathDefinition.',
            date_update INTEGER DEFAULT NULL,
            file_modified INTEGER DEFAULT NULL
        )');
        $this->addSql('INSERT INTO storage__new (id, name, type, path, date_update, file_modified)
            SELECT id, name, type, '.$pathExpression.', date_update, file_modified FROM storage');
        $this->addSql('DROP TABLE storage');
        $this->addSql('ALTER TABLE storage__new RENAME TO storage');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    private function assertNoForeignKeyViolations(): void
    {
        $violations = $this->connection->fetchAllAssociative('PRAGMA foreign_key_check');

        $this->abortIf(
            $violations !== [],
            'Foreign key violations detected after rebuilding storage: '.json_encode($violations),
        );
    }
}
