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
 * Drops anime.metadata (issue #300), the final step of the catalog persist refactor: every
 * segment that used to live in this JSON blob has already moved to its own table — external
 * ids (#297, anime_external_id), descriptions (#298, anime_description) and plugin filler data
 * (#299, anime_plugin_data) — so the column is unused dead weight by this point.
 *
 * SQLite has no DROP COLUMN before 3.35 (bundled libsqlite3 in FrankenPHP's PHP build is not
 * guaranteed >= 3.35) — full table rebuild, same pattern as other irreversible-by-simple-SQL
 * migrations in this project (see gotchas.md and Version20260712000002::down()). Rebuilding
 * drops the anime_fts sync triggers (Version20260713000000) and the anime indexes along with
 * the table itself, so both are recreated below.
 *
 * `anime` is referenced by ON DELETE CASCADE from anime_genres, anime_studios, anime_labels,
 * anime_name, anime_external_id, anime_description and anime_plugin_data. With
 * "PRAGMA foreign_keys = ON" (set on every connection by the EnableForeignKeys middleware, see
 * gotchas.md), DROP TABLE performs an implicit DELETE of every row first, which fires those
 * cascades and wipes all of the above tables. "PRAGMA foreign_keys" is a no-op inside a
 * transaction, so isTransactional() is turned off and the pragma is toggled OFF for the rebuild
 * and back ON afterwards, matching SQLite's documented 12-step ALTER TABLE procedure.
 */
final class Version20260801000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop anime.metadata: every segment it held has moved to its own table (issue #300)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');

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
            date_add INTEGER NOT NULL,
            date_update INTEGER NOT NULL,
            storage_path VARCHAR(1024) DEFAULT NULL,
            normalized_title VARCHAR(256) NOT NULL DEFAULT \'\',
            demographic VARCHAR(16) DEFAULT NULL CHECK (demographic IS NULL OR demographic IN (\'shounen\', \'shoujo\', \'seinen\', \'josei\', \'kids\')),
            CHECK (date_end IS NULL OR date_premiere IS NULL OR date_end >= date_premiere),
            CONSTRAINT FK_ANIME_STORAGE FOREIGN KEY (storage_id) REFERENCES storage (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO anime__new (
            id, title, date_premiere, date_end, duration_minutes, episodes_count, watched_episodes,
            watch_status, user_rating, notes, type, countries, cover, storage_id, date_add, date_update,
            storage_path, normalized_title, demographic
        ) SELECT
            id, title, date_premiere, date_end, duration_minutes, episodes_count, watched_episodes,
            watch_status, user_rating, notes, type, countries, cover, storage_id, date_add, date_update,
            storage_path, normalized_title, demographic
        FROM anime');
        $this->addSql('DROP TABLE anime');
        $this->addSql('ALTER TABLE anime__new RENAME TO anime');

        $this->addSql('CREATE INDEX IDX_ANIME_STORAGE ON anime (storage_id)');
        $this->addSql('CREATE INDEX IDX_ANIME_NORMALIZED_TITLE ON anime (normalized_title)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ANIME_STORAGE_STORAGE_PATH ON anime (storage_id, storage_path)');

        $this->addSql('
            CREATE TRIGGER anime_fts_ai_anime AFTER INSERT ON anime BEGIN
                INSERT INTO anime_fts(rowid, anime_id, name) VALUES (new.id, new.id, new.title);
            END
        ');
        $this->addSql('
            CREATE TRIGGER anime_fts_au_anime AFTER UPDATE OF title ON anime BEGIN
                UPDATE anime_fts SET name = new.title WHERE rowid = new.id;
            END
        ');
        $this->addSql('
            CREATE TRIGGER anime_fts_ad_anime AFTER DELETE ON anime BEGIN
                DELETE FROM anime_fts WHERE rowid = old.id;
            END
        ');

        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function postUp(Schema $schema): void
    {
        $violations = $this->connection->fetchAllAssociative('PRAGMA foreign_key_check');

        $this->abortIf(
            $violations !== [],
            'Foreign key violations detected after rebuilding anime: ' . json_encode($violations),
        );
    }

    public function down(Schema $schema): void
    {
        // Data loss is accepted here: the column held nothing but leftover fragments the
        // three prior migrations (#297/#298/#299) had already stripped out, so there is
        // nothing meaningful left to restore into it.
        $this->addSql('ALTER TABLE anime ADD COLUMN metadata CLOB DEFAULT NULL');
    }
}
