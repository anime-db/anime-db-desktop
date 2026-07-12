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

final class Version20260712000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create anime_themes: the theme axis of MAL taxonomy (issue #185), same join-table pattern as anime_genres';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anime_themes (
            anime_id INTEGER NOT NULL,
            theme_code VARCHAR(32) NOT NULL CHECK (theme_code IN (
                \'harem\', \'historical\', \'isekai\', \'martial-arts\', \'mecha\', \'military\', \'music\',
                \'mythology\', \'parody\', \'psychological\', \'school\', \'strategy-game\', \'super-power\', \'vampire\'
            )),
            PRIMARY KEY (anime_id, theme_code),
            CONSTRAINT FK_ANIME_THEMES_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE anime_themes');
    }
}
