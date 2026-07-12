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

final class Version20260713000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anime_fts FTS5 virtual table (issue #195) for the anime list name quick-filter, '
            .'covering anime.title and anime_name.name and kept in sync via triggers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE VIRTUAL TABLE anime_fts USING fts5(name, anime_id UNINDEXED)');

        // anime.title -> one anime_fts row per anime, rowid = anime.id.
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

        // anime_name.name -> one anime_fts row per anime_name row, rowid = -anime_name.id
        // (negative namespace so it never collides with an anime.id rowid above, since
        // anime_name.id is always a positive autoincrement value).
        $this->addSql('
            CREATE TRIGGER anime_fts_ai_anime_name AFTER INSERT ON anime_name BEGIN
                INSERT INTO anime_fts(rowid, anime_id, name) VALUES (-new.id, new.anime_id, new.name);
            END
        ');
        $this->addSql('
            CREATE TRIGGER anime_fts_au_anime_name AFTER UPDATE OF name ON anime_name BEGIN
                UPDATE anime_fts SET name = new.name WHERE rowid = -new.id;
            END
        ');
        $this->addSql('
            CREATE TRIGGER anime_fts_ad_anime_name AFTER DELETE ON anime_name BEGIN
                DELETE FROM anime_fts WHERE rowid = -old.id;
            END
        ');

        // Backfill anime_fts for rows that already existed before this migration.
        $this->addSql('INSERT INTO anime_fts(rowid, anime_id, name) SELECT id, id, title FROM anime');
        $this->addSql('INSERT INTO anime_fts(rowid, anime_id, name) SELECT -id, anime_id, name FROM anime_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER anime_fts_ad_anime_name');
        $this->addSql('DROP TRIGGER anime_fts_au_anime_name');
        $this->addSql('DROP TRIGGER anime_fts_ai_anime_name');
        $this->addSql('DROP TRIGGER anime_fts_ad_anime');
        $this->addSql('DROP TRIGGER anime_fts_au_anime');
        $this->addSql('DROP TRIGGER anime_fts_ai_anime');
        $this->addSql('DROP TABLE anime_fts');
    }
}
