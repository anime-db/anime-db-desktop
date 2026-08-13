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

final class Version20260712000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen anime_themes.theme_code CHECK constraint to the full real MAL theme axis (issue #191): '
            .'add 38 themes missing from the redistributed subset (adult-cast, gore, samurai, urban-fantasy, etc.)';
    }

    public function up(Schema $schema): void
    {
        // SQLite has no ALTER CHECK — full table rebuild, same pattern as other
        // irreversible-by-simple-SQL migrations in this project (see gotchas.md).
        $this->addSql('CREATE TABLE anime_themes__new (
            anime_id INTEGER NOT NULL,
            theme_code VARCHAR(32) NOT NULL CHECK (theme_code IN (
                \'adult-cast\', \'anthropomorphic\', \'cgdct\', \'childcare\', \'combat-sports\', \'crossdressing\',
                \'delinquents\', \'detective\', \'educational\', \'gag-humor\', \'gore\', \'harem\',
                \'high-stakes-game\', \'historical\', \'idols-female\', \'idols-male\', \'isekai\', \'iyashikei\',
                \'love-polygon\', \'love-status-quo\', \'magical-sex-shift\', \'mahou-shoujo\', \'martial-arts\',
                \'mecha\', \'medical\', \'military\', \'music\', \'mythology\', \'organized-crime\',
                \'otaku-culture\', \'parody\', \'performing-arts\', \'pets\', \'psychological\', \'racing\',
                \'reincarnation\', \'reverse-harem\', \'samurai\', \'school\', \'showbiz\', \'space\',
                \'strategy-game\', \'super-power\', \'survival\', \'team-sports\', \'time-travel\',
                \'urban-fantasy\', \'vampire\', \'video-game\', \'villainess\', \'visual-arts\', \'workplace\'
            )),
            PRIMARY KEY (anime_id, theme_code),
            CONSTRAINT FK_ANIME_THEMES_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO anime_themes__new (anime_id, theme_code) SELECT anime_id, theme_code FROM anime_themes');
        $this->addSql('DROP TABLE anime_themes');
        $this->addSql('ALTER TABLE anime_themes__new RENAME TO anime_themes');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anime_themes__new (
            anime_id INTEGER NOT NULL,
            theme_code VARCHAR(32) NOT NULL CHECK (theme_code IN (
                \'harem\', \'historical\', \'isekai\', \'martial-arts\', \'mecha\', \'military\', \'music\',
                \'mythology\', \'parody\', \'psychological\', \'school\', \'strategy-game\', \'super-power\', \'vampire\'
            )),
            PRIMARY KEY (anime_id, theme_code),
            CONSTRAINT FK_ANIME_THEMES_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql("INSERT INTO anime_themes__new (anime_id, theme_code)
            SELECT anime_id, theme_code
            FROM anime_themes
            WHERE theme_code IN (
                'harem', 'historical', 'isekai', 'martial-arts', 'mecha', 'military', 'music',
                'mythology', 'parody', 'psychological', 'school', 'strategy-game', 'super-power', 'vampire'
            )");
        $this->addSql('DROP TABLE anime_themes');
        $this->addSql('ALTER TABLE anime_themes__new RENAME TO anime_themes');
    }
}
