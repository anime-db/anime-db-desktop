<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `downloads` table backing the qBittorrent-based DownloadServiceInterface
 * implementation (issue #346): one row per (info_hash, anime_id) pairing, N:M by design so a
 * season pack's single infoHash can be linked to several Anime rows.
 *
 * No CHECK constraint on `status` (see App\Entity\Enum\DownloadStatus): SQLite has no
 * ALTER-friendly CHECK, so a future extra status would otherwise force a full table rebuild
 * (see .claude-docs/gotchas.md and the Doctrine-сущность recipe in cookbook.md) — `enumType`
 * already validates it at the application boundary.
 */
final class Version20260809000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create downloads table: (info_hash, anime_id, status) pairing for the qBittorrent download service (issue #346)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE downloads (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            info_hash VARCHAR(40) NOT NULL,
            anime_id INTEGER NOT NULL,
            status VARCHAR(16) NOT NULL,
            date_add INTEGER NOT NULL,
            CONSTRAINT FK_DOWNLOAD_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_download_infohash_anime ON downloads (info_hash, anime_id)');
        $this->addSql('CREATE INDEX IDX_DOWNLOAD_INFO_HASH ON downloads (info_hash)');
        $this->addSql('CREATE INDEX IDX_DOWNLOAD_ANIME ON downloads (anime_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE downloads');
    }
}
