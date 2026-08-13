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

final class Version20260704000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog entities: storage, studio, label, anime (+ genres/studios/labels/names/images/sources)';
    }

    public function up(Schema $schema): void
    {
        // The Stage-0 anime placeholder (id, title, metadata) is replaced by the full catalog schema below.
        $this->addSql('DROP TABLE anime');

        // date_update/file_modified/date_premiere/date_end/date_add are stored as Unix
        // timestamps (INTEGER), not DATE/DATETIME, to avoid timezone-dependent string parsing.
        $this->addSql('CREATE TABLE storage (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(256) NOT NULL,
            type VARCHAR(16) NOT NULL CHECK (type IN (\'folder\', \'external\', \'external-r\', \'video\')),
            path VARCHAR(1024) NOT NULL,
            date_update INTEGER DEFAULT NULL,
            file_modified INTEGER DEFAULT NULL
        )');

        $this->addSql('CREATE TABLE studio (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(256) NOT NULL
        )');

        $this->addSql('CREATE TABLE label (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(32) NOT NULL
        )');

        $this->addSql('CREATE TABLE anime (
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
        $this->addSql('CREATE INDEX IDX_ANIME_STORAGE ON anime (storage_id)');

        $this->addSql('CREATE TABLE anime_genres (
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

        $this->addSql('CREATE TABLE anime_studios (
            anime_id INTEGER NOT NULL,
            studio_id INTEGER NOT NULL,
            PRIMARY KEY (anime_id, studio_id),
            CONSTRAINT FK_ANIME_STUDIOS_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_ANIME_STUDIOS_STUDIO FOREIGN KEY (studio_id) REFERENCES studio (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_ANIME_STUDIOS_STUDIO ON anime_studios (studio_id)');

        $this->addSql('CREATE TABLE anime_labels (
            anime_id INTEGER NOT NULL,
            label_id INTEGER NOT NULL,
            PRIMARY KEY (anime_id, label_id),
            CONSTRAINT FK_ANIME_LABELS_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_ANIME_LABELS_LABEL FOREIGN KEY (label_id) REFERENCES label (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_ANIME_LABELS_LABEL ON anime_labels (label_id)');

        $this->addSql('CREATE TABLE anime_name (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            name VARCHAR(256) NOT NULL,
            type VARCHAR(16) NOT NULL CHECK (type IN (\'original\', \'english\', \'russian\', \'synonym\')),
            CONSTRAINT FK_ANIME_NAME_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_ANIME_NAME_ANIME ON anime_name (anime_id)');

        $this->addSql('CREATE TABLE anime_image (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            source VARCHAR(256) NOT NULL,
            CONSTRAINT FK_ANIME_IMAGE_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_ANIME_IMAGE_ANIME ON anime_image (anime_id)');

        $this->addSql('CREATE TABLE anime_source (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            url VARCHAR(512) NOT NULL,
            CONSTRAINT FK_ANIME_SOURCE_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_ANIME_SOURCE_ANIME ON anime_source (anime_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE anime_source');
        $this->addSql('DROP TABLE anime_image');
        $this->addSql('DROP TABLE anime_name');
        $this->addSql('DROP TABLE anime_labels');
        $this->addSql('DROP TABLE anime_studios');
        $this->addSql('DROP TABLE anime_genres');
        $this->addSql('DROP TABLE anime');
        $this->addSql('DROP TABLE label');
        $this->addSql('DROP TABLE studio');
        $this->addSql('DROP TABLE storage');

        $this->addSql('CREATE TABLE anime (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, title VARCHAR(255) NOT NULL, metadata CLOB DEFAULT NULL)');
    }
}
