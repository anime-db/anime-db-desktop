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
 * One torrent backs exactly one anime: `downloads.info_hash` becomes UNIQUE, so the invariant is
 * held by the storage and survives concurrent enqueue() calls (see QbittorrentDownloadService).
 *
 * Rows that already share an info_hash (the former N:M pairing) are deduplicated first: the
 * oldest row (lowest id) per info_hash is kept, the others are deleted — otherwise CREATE UNIQUE
 * INDEX would fail on such installations. The torrent itself stays in qBittorrent untouched.
 *
 * The composite (info_hash, anime_id) unique index and the plain info_hash index are superseded
 * by the new unique index and dropped.
 *
 * The number of deleted rows is written to the migration log before the deletion. Note that a
 * deleted row may be a still-pending pairing, which the poller will then never complete.
 *
 * down() restores only the previous set of indexes; the rows deleted by up() are NOT restored.
 */
final class Version20260925000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make downloads.info_hash unique: one torrent is linked to exactly one anime';
    }

    public function up(Schema $schema): void
    {
        $duplicates = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM downloads WHERE id NOT IN (SELECT MIN(id) FROM downloads GROUP BY info_hash)',
        );
        $this->write(\sprintf('Deleting %d duplicate downloads row(s) that share an info_hash with an older row.', $duplicates));

        $this->addSql('DELETE FROM downloads WHERE id NOT IN (SELECT MIN(id) FROM downloads GROUP BY info_hash)');
        $this->addSql('DROP INDEX uniq_download_infohash_anime');
        $this->addSql('DROP INDEX IDX_DOWNLOAD_INFO_HASH');
        $this->addSql('CREATE UNIQUE INDEX uniq_download_infohash ON downloads (info_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_download_infohash');
        $this->addSql('CREATE INDEX IDX_DOWNLOAD_INFO_HASH ON downloads (info_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_download_infohash_anime ON downloads (info_hash, anime_id)');
    }
}
