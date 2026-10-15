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

use App\Entity\SourceUrl;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Source link comparison form (issue #1056): `anime_source.normalized_url` holds the url after the
 * common normalization steps and is indexed; existing rows are filled in postUp() (SQLite cannot
 * compute it in SQL). Also creates the `app_meta` key-value table.
 */
final class Version20261012000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anime_source.normalized_url with an index and the app_meta table (issue #1056)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE anime_source ADD COLUMN normalized_url VARCHAR(1024) NOT NULL DEFAULT ''");
        $this->addSql('CREATE INDEX IDX_ANIME_SOURCE_NORMALIZED_URL ON anime_source (normalized_url)');
        $this->addSql('CREATE TABLE app_meta ("key" VARCHAR(64) NOT NULL, value CLOB NOT NULL, PRIMARY KEY ("key"))');
    }

    public function postUp(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, url FROM anime_source');
        foreach ($rows as $row) {
            $this->connection->executeStatement(
                'UPDATE anime_source SET normalized_url = ? WHERE id = ?',
                [SourceUrl::common((string) $row['url']), $row['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_meta');
        $this->addSql('DROP INDEX IDX_ANIME_SOURCE_NORMALIZED_URL');
        $this->addSql('ALTER TABLE anime_source DROP COLUMN normalized_url');
    }
}
