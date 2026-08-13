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

final class Version20260709000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anime.storage_path: the top-level file/folder name inside storage.path that this anime is linked to';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anime ADD COLUMN storage_path VARCHAR(1024) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite has no DROP COLUMN before 3.35 (bundled libsqlite3 in FrankenPHP's PHP
        // build is not guaranteed >= 3.35) — full table rebuild, same pattern as other
        // irreversible-by-simple-SQL migrations in this project (see gotchas.md).
        $this->addSql('CREATE TABLE anime__new (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            title VARCHAR(256) NOT NULL,
            date_premiere INTEGER DEFAULT NULL,
            date_end INTEGER DEFAULT NULL,
            duration_minutes INTEGER DEFAULT NULL,
            episodes_count INTEGER DEFAULT NULL,
            watched_episodes INTEGER DEFAULT NULL,
            watch_status VARCHAR(16) NOT NULL CHECK (watch_status IN (\'plan\', \'watching\', \'completed\', \'dropped\', \'on_hold\')),
            user_rating INTEGER DEFAULT NULL,
            notes CLOB DEFAULT NULL,
            type VARCHAR(16) NOT NULL CHECK (type IN (\'tv\', \'movie\', \'ova\', \'ona\', \'special\', \'music\')),
            countries CLOB DEFAULT NULL,
            cover VARCHAR(256) DEFAULT NULL,
            storage_id INTEGER DEFAULT NULL,
            metadata CLOB DEFAULT NULL,
            date_add INTEGER NOT NULL,
            date_update INTEGER NOT NULL,
            CHECK (date_end IS NULL OR date_premiere IS NULL OR date_end >= date_premiere),
            CONSTRAINT FK_ANIME_STORAGE FOREIGN KEY (storage_id) REFERENCES storage (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO anime__new (
            id, title, date_premiere, date_end, duration_minutes, episodes_count, watched_episodes,
            watch_status, user_rating, notes, type, countries, cover, storage_id, metadata, date_add, date_update
        ) SELECT
            id, title, date_premiere, date_end, duration_minutes, episodes_count, watched_episodes,
            watch_status, user_rating, notes, type, countries, cover, storage_id, metadata, date_add, date_update
        FROM anime');
        $this->addSql('DROP TABLE anime');
        $this->addSql('ALTER TABLE anime__new RENAME TO anime');
        $this->addSql('CREATE INDEX IDX_ANIME_STORAGE ON anime (storage_id)');
    }
}
