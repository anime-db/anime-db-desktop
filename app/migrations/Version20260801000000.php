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
 * Split anime.metadata['descriptions'][locale] out into its own anime_description table
 * (issue #298): a catalog list query no longer has to load every locale's description text
 * for every row along with the metadata JSON blob, only the anime detail page ever touches
 * this data (Anime::getSummary(), lazily, via the OneToMany relation).
 */
final class Version20260801000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create anime_description table and migrate metadata.descriptions{} into it (issue #298)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anime_description (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            locale VARCHAR(8) NOT NULL,
            description CLOB NOT NULL,
            CONSTRAINT FK_ANIME_DESCRIPTION_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ANIME_DESCRIPTION_ANIME_LOCALE ON anime_description (anime_id, locale)');

        // metadata.descriptions is a JSON object {"ru": "...", "en": "..."} — json_each()
        // unpacks it to one (anime_id, locale, description) row per key.
        $this->addSql("
            INSERT INTO anime_description (anime_id, locale, description)
            SELECT anime.id, je.key, je.value
            FROM anime, json_each(anime.metadata, '\$.descriptions') je
        ");

        // Strip the now-migrated key out of the JSON blob, then collapse '{}' back to NULL
        // so a row whose metadata only ever held descriptions ends up with metadata = NULL,
        // same as a row that never had any metadata at all.
        $this->addSql("UPDATE anime SET metadata = json_remove(metadata, '\$.descriptions') WHERE json_extract(metadata, '\$.descriptions') IS NOT NULL");
        $this->addSql("UPDATE anime SET metadata = NULL WHERE metadata = '{}'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            UPDATE anime SET metadata = json_set(
                COALESCE(metadata, '{}'),
                '\$.descriptions',
                (
                    SELECT json_group_object(locale, description)
                    FROM anime_description
                    WHERE anime_description.anime_id = anime.id
                )
            )
            WHERE anime.id IN (SELECT DISTINCT anime_id FROM anime_description)
        ");

        $this->addSql('DROP TABLE anime_description');
    }
}
