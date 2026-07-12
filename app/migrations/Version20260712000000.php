<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Narrow anime_genres.genre_code CHECK constraint to the final 13-value MAL genre axis (issue #183): rename dementia -> avant-garde and thriller -> suspense, drop ecchi/magic/themes/demographics values';
    }

    public function up(Schema $schema): void
    {
        // SQLite has no ALTER CHECK — full table rebuild, same pattern as other
        // irreversible-by-simple-SQL migrations in this project (see gotchas.md).
        $this->addSql('CREATE TABLE anime_genres__new (
            anime_id INTEGER NOT NULL,
            genre_code VARCHAR(32) NOT NULL CHECK (genre_code IN (
                \'action\', \'adventure\', \'avant-garde\', \'comedy\', \'drama\', \'fantasy\', \'horror\',
                \'mystery\', \'romance\', \'sci-fi\', \'slice-of-life\', \'sports\', \'supernatural\', \'suspense\'
            )),
            PRIMARY KEY (anime_id, genre_code),
            CONSTRAINT FK_ANIME_GENRES_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql("INSERT INTO anime_genres__new (anime_id, genre_code)
            SELECT anime_id, CASE genre_code WHEN 'dementia' THEN 'avant-garde' WHEN 'thriller' THEN 'suspense' ELSE genre_code END
            FROM anime_genres
            WHERE genre_code IN (
                'action', 'adventure', 'comedy', 'drama', 'fantasy', 'horror',
                'mystery', 'romance', 'sci-fi', 'slice-of-life', 'sports', 'supernatural', 'dementia', 'thriller'
            )");
        $this->addSql('DROP TABLE anime_genres');
        $this->addSql('ALTER TABLE anime_genres__new RENAME TO anime_genres');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anime_genres__new (
            anime_id INTEGER NOT NULL,
            genre_code VARCHAR(32) NOT NULL CHECK (genre_code IN (
                \'action\', \'adventure\', \'comedy\', \'drama\', \'fantasy\', \'horror\', \'mecha\', \'music\',
                \'mystery\', \'psychological\', \'romance\', \'sci-fi\', \'slice-of-life\', \'sports\',
                \'supernatural\', \'thriller\', \'ecchi\', \'harem\', \'isekai\', \'magic\', \'martial-arts\',
                \'military\', \'historical\', \'parody\', \'school\', \'shounen\', \'shoujo\', \'seinen\',
                \'josei\', \'super-power\', \'vampire\', \'demons\', \'game\', \'kids\', \'dementia\'
            )),
            PRIMARY KEY (anime_id, genre_code),
            CONSTRAINT FK_ANIME_GENRES_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql("INSERT INTO anime_genres__new (anime_id, genre_code)
            SELECT anime_id, CASE genre_code WHEN 'avant-garde' THEN 'dementia' WHEN 'suspense' THEN 'thriller' ELSE genre_code END
            FROM anime_genres");
        $this->addSql('DROP TABLE anime_genres');
        $this->addSql('ALTER TABLE anime_genres__new RENAME TO anime_genres');
    }
}
