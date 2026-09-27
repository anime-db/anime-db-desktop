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
 * `anime_studios` and `anime_labels` are the join tables of the `Anime::$studios` and
 * `Anime::$labels` ManyToMany associations, declared via `#[ORM\JoinTable]`. That attribute has no
 * parameter for declaring indexes (unlike `#[ORM\Table]`, which has `indexes`/`uniqueConstraints`),
 * so the index names created by earlier migrations (`IDX_ANIME_STUDIOS_STUDIO`,
 * `IDX_ANIME_LABELS_LABEL`) can never be matched from the mapping side.
 *
 * For a join table Doctrine instead derives an autoindex name per column as a hash of the table
 * and column name — hence names like `IDX_2C2AD578794BBE89` below instead of a readable one. The
 * only way to converge with these expectations is from the schema side. Doctrine also expects an
 * index on both association columns; only one side had an index in the database.
 *
 * DROP INDEX / CREATE INDEX only: neither table is rebuilt, so no data, triggers or foreign keys
 * are affected.
 */
final class Version20260927000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename anime_studios/anime_labels join indexes to match Doctrine autoindex names and add the missing anime_id index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_ANIME_STUDIOS_STUDIO');
        $this->addSql('CREATE INDEX IDX_2C2AD578794BBE89 ON anime_studios (anime_id)');
        $this->addSql('CREATE INDEX IDX_2C2AD578446F285F ON anime_studios (studio_id)');

        $this->addSql('DROP INDEX IDX_ANIME_LABELS_LABEL');
        $this->addSql('CREATE INDEX IDX_3DB864C794BBE89 ON anime_labels (anime_id)');
        $this->addSql('CREATE INDEX IDX_3DB864C33B92F39 ON anime_labels (label_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_2C2AD578446F285F');
        $this->addSql('DROP INDEX IDX_2C2AD578794BBE89');
        $this->addSql('CREATE INDEX IDX_ANIME_STUDIOS_STUDIO ON anime_studios (studio_id)');

        $this->addSql('DROP INDEX IDX_3DB864C33B92F39');
        $this->addSql('DROP INDEX IDX_3DB864C794BBE89');
        $this->addSql('CREATE INDEX IDX_ANIME_LABELS_LABEL ON anime_labels (label_id)');
    }
}
