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
 * Ledger of the files plugins wrote into a record folder (issue #1065).
 */
final class Version20261013000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the record_folder_file table (issue #1065)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE record_folder_file (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            plugin_id VARCHAR(128) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            sha256 VARCHAR(64) NOT NULL,
            written_at INTEGER NOT NULL,
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RECORD_FOLDER_FILE_PLUGIN_ANIME_NAME ON record_folder_file (plugin_id, anime_id, file_name)');
        $this->addSql('CREATE INDEX IDX_RECORD_FOLDER_FILE_ANIME ON record_folder_file (anime_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE record_folder_file');
    }
}
