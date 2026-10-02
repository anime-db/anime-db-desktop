<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 *
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
 * Adds the completion snapshot (downloads.storage_id/storage_path) and the optimistic-lock
 * version column (downloads.version) that issue #837 needs: app:downloads:unlink clears the
 * linked anime's storage pointer only when it still matches this snapshot, and guards its
 * conditional DELETE against a concurrent DownloadCompletionPoller pass with the version column.
 */
final class Version20261002000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add downloads.storage_id/storage_path (completion snapshot) and downloads.version (optimistic lock)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE downloads ADD COLUMN storage_id INTEGER DEFAULT NULL REFERENCES storage (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_DOWNLOAD_STORAGE ON downloads (storage_id)');
        $this->addSql('ALTER TABLE downloads ADD COLUMN storage_path VARCHAR(1024) DEFAULT NULL');
        $this->addSql('ALTER TABLE downloads ADD COLUMN version INTEGER DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite has no DROP COLUMN before 3.35 (bundled libsqlite3 in FrankenPHP's PHP build is
        // not guaranteed >= 3.35) — full table rebuild, same pattern as other
        // irreversible-by-simple-SQL migrations in this project (see gotchas.md).
        $this->addSql('DROP INDEX IDX_DOWNLOAD_STORAGE');
        $this->addSql('CREATE TABLE downloads__new (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            info_hash VARCHAR(40) NOT NULL,
            anime_id INTEGER NOT NULL,
            status VARCHAR(16) NOT NULL,
            date_add INTEGER NOT NULL,
            CONSTRAINT FK_DOWNLOAD_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO downloads__new (id, info_hash, anime_id, status, date_add)
            SELECT id, info_hash, anime_id, status, date_add FROM downloads');
        $this->addSql('DROP TABLE downloads');
        $this->addSql('ALTER TABLE downloads__new RENAME TO downloads');
        $this->addSql('CREATE UNIQUE INDEX uniq_download_infohash ON downloads (info_hash)');
        $this->addSql('CREATE INDEX IDX_DOWNLOAD_ANIME ON downloads (anime_id)');
    }
}
