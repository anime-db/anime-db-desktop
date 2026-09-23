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
 * Replaces anime_name.type (the mixed AnimeNameType enum: original/english/russian/synonym)
 * with two independent columns, locale and role (issue #724) — see App\Entity\Enum\AnimeNameRole.
 *
 * `type` sits in a table-level CHECK and is NOT NULL, and SQLite cannot drop a CHECK or
 * (on a libsqlite3 build older than 3.35, not guaranteed in FrankenPHP's bundled PHP) drop a
 * column — full table rebuild, same pattern as the other irreversible-by-simple-SQL migrations
 * in this project (see gotchas.md and Version20260801000003::up()).
 *
 * `anime_name` has no ON DELETE CASCADE children of its own (unlike `anime`), so unlike
 * Version20260801000003 this rebuild does not need "PRAGMA foreign_keys = OFF/ON" around it —
 * dropping the child side of a foreign key does not fire the parent's cascade actions.
 *
 * `id` is carried over explicitly in the INSERT ... SELECT below: anime_fts addresses these
 * rows as `rowid = -anime_name.id` (Version20260713000000), and DROP TABLE does not fire
 * triggers, so an anime_fts row would otherwise survive the rebuild pointing at an id that no
 * longer matches its anime_name row. The three anime_fts_*_anime_name triggers and the two
 * anime_name indexes are dropped along with the table itself and are recreated below.
 *
 * Old rows get role = 'synonym' when type was 'synonym', else 'official' (original/english/
 * russian all named an official title, just for a different display language) — and locale =
 * NULL unconditionally, for every row, with no exception: the old `type` was a display-language
 * label ("what to show an English-speaking user"), not a claim about what language the string
 * is actually written in, so it cannot be translated into `locale` without inventing data (the
 * demo seed's own `English` rows include `Gintama` and `Sousou no Frieren`, both romanizations,
 * not English). PluginAnimeDataMerger's "a null locale yields to a known one" rule (see its
 * class docblock) self-heals these on the next fill.
 */
final class Version20260923000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace anime_name.type (AnimeNameType) with locale + role (AnimeNameRole), '
            .'two independent axes instead of one mixed enum (issue #724)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anime_name__new (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            name VARCHAR(256) NOT NULL,
            normalized_name VARCHAR(256) NOT NULL DEFAULT \'\',
            locale VARCHAR(16) DEFAULT NULL,
            role VARCHAR(16) NOT NULL CHECK (role IN (\'official\', \'synonym\', \'short\')),
            CONSTRAINT FK_ANIME_NAME_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO anime_name__new (id, anime_id, name, normalized_name, locale, role)
            SELECT id, anime_id, name, normalized_name, NULL, CASE WHEN type = \'synonym\' THEN \'synonym\' ELSE \'official\' END
            FROM anime_name');
        $this->addSql('DROP TABLE anime_name');
        $this->addSql('ALTER TABLE anime_name__new RENAME TO anime_name');

        $this->addSql('CREATE INDEX IDX_ANIME_NAME_ANIME ON anime_name (anime_id)');
        $this->addSql('CREATE INDEX IDX_ANIME_NAME_NORMALIZED_NAME ON anime_name (normalized_name)');

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
    }

    public function down(Schema $schema): void
    {
        // Lossy: `short` role and any `locale` were never expressible in the old `type`, so a
        // `short`-role row folds into 'synonym' — the same acceptance the up() docblock explains
        // for the forward direction. Project has not shipped yet (see class docblock), so there
        // is no real user data this could affect.
        $this->addSql('CREATE TABLE anime_name__new (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            name VARCHAR(256) NOT NULL,
            type VARCHAR(16) NOT NULL CHECK (type IN (\'original\', \'english\', \'russian\', \'synonym\')),
            normalized_name VARCHAR(256) NOT NULL DEFAULT \'\',
            CONSTRAINT FK_ANIME_NAME_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO anime_name__new (id, anime_id, name, normalized_name, type)
            SELECT id, anime_id, name, normalized_name, CASE WHEN role = \'official\' THEN \'original\' ELSE \'synonym\' END
            FROM anime_name');
        $this->addSql('DROP TABLE anime_name');
        $this->addSql('ALTER TABLE anime_name__new RENAME TO anime_name');

        $this->addSql('CREATE INDEX IDX_ANIME_NAME_ANIME ON anime_name (anime_id)');
        $this->addSql('CREATE INDEX IDX_ANIME_NAME_NORMALIZED_NAME ON anime_name (normalized_name)');

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
    }
}
