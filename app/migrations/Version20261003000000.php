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
 * Adds downloads.target_storage_id (the Storage a download was enqueued into, issue #851) and
 * downloads.failure_reason (a code for why a row is Failed). A pre-#851 Pending row has no target
 * storage by definition — it was enqueued under the old single downloadsRoot layout, which the
 * new jail/linker no longer understand — so every such row is failed here with
 * failure_reason = "legacy_layout"; its files on disk and its torrent in qBittorrent are not
 * touched, only this app's own bookkeeping. A Completed row is left exactly as it is: it already
 * has its own (storage, path) completion snapshot from issue #837 and does not need a target
 * storage to stay meaningful.
 */
final class Version20261003000000 extends AbstractMigration
{
    private const string LEGACY_LAYOUT_FAILURE_REASON = 'legacy_layout';

    public function getDescription(): string
    {
        return 'Add downloads.target_storage_id/failure_reason and fail legacy Pending rows with no target storage (issue #851)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE downloads ADD COLUMN target_storage_id INTEGER DEFAULT NULL REFERENCES storage (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_DOWNLOAD_TARGET_STORAGE ON downloads (target_storage_id)');
        $this->addSql('ALTER TABLE downloads ADD COLUMN failure_reason VARCHAR(32) DEFAULT NULL');

        $this->addSql(\sprintf(
            "UPDATE downloads SET status = 'failed', failure_reason = '%s' WHERE status = 'pending' AND target_storage_id IS NULL",
            self::LEGACY_LAYOUT_FAILURE_REASON,
        ));
    }

    public function down(Schema $schema): void
    {
        // SQLite has no DROP COLUMN before 3.35 (bundled libsqlite3 in FrankenPHP's PHP build is
        // not guaranteed >= 3.35) — full table rebuild, same pattern as other
        // irreversible-by-simple-SQL migrations in this project (see gotchas.md). The status/
        // failure_reason change up() made to legacy rows is data, not schema, and is not undone.
        $this->addSql('DROP INDEX IDX_DOWNLOAD_TARGET_STORAGE');
        $this->addSql('CREATE TABLE downloads__new (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            info_hash VARCHAR(40) NOT NULL,
            anime_id INTEGER NOT NULL,
            status VARCHAR(16) NOT NULL,
            date_add INTEGER NOT NULL,
            storage_id INTEGER DEFAULT NULL REFERENCES storage (id) ON DELETE SET NULL,
            storage_path VARCHAR(1024) DEFAULT NULL,
            version INTEGER DEFAULT 1 NOT NULL,
            CONSTRAINT FK_DOWNLOAD_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO downloads__new (id, info_hash, anime_id, status, date_add, storage_id, storage_path, version)
            SELECT id, info_hash, anime_id, status, date_add, storage_id, storage_path, version FROM downloads');
        $this->addSql('DROP TABLE downloads');
        $this->addSql('ALTER TABLE downloads__new RENAME TO downloads');
        $this->addSql('CREATE UNIQUE INDEX uniq_download_infohash ON downloads (info_hash)');
        $this->addSql('CREATE INDEX IDX_DOWNLOAD_ANIME ON downloads (anime_id)');
        $this->addSql('CREATE INDEX IDX_DOWNLOAD_STORAGE ON downloads (storage_id)');
    }
}
