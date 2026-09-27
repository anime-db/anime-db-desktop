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
 * `anime_genres`, `anime_themes`, `anime_sync_state` and `anime_external_id` each have an
 * `anime_id` foreign key column, but earlier migrations never created an index on it. Doctrine
 * derives an autoindex name for it as a hash of the table and column name — hence names like
 * `IDX_1EE1614B794BBE89` below instead of a readable one; see `Version20260927000000` for the
 * same pattern on the `anime_studios`/`anime_labels` join tables.
 *
 * All four indexes created here duplicate the first column of the composite PRIMARY KEY of their
 * table ((anime_id, genre_code), (anime_id, theme_code), (anime_id, participant_id) and
 * (anime_id, plugin_id) respectively) and are therefore redundant for query planning — SQLite can
 * already use the PRIMARY KEY prefix. They exist solely to match what Doctrine expects to find:
 * `Doctrine\DBAL\Schema\Index::isFulfilledBy()` does not treat a composite PRIMARY KEY as covering
 * a single-column index on its first column, so without them `doctrine:schema:update --dump-sql`
 * reports the mapping and schema as diverged. DO NOT DROP these indexes as apparent duplicates —
 * doing so silently reintroduces that divergence.
 *
 * CREATE INDEX only: no table is rebuilt, so no data, triggers or foreign keys are affected.
 */
final class Version20260927000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the missing anime_id indexes on anime_genres, anime_themes, anime_sync_state and anime_external_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_1EE1614B794BBE89 ON anime_genres (anime_id)');
        $this->addSql('CREATE INDEX IDX_A348B683794BBE89 ON anime_themes (anime_id)');
        $this->addSql('CREATE INDEX IDX_4214C246794BBE89 ON anime_sync_state (anime_id)');
        $this->addSql('CREATE INDEX IDX_82C5714E794BBE89 ON anime_external_id (anime_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_1EE1614B794BBE89');
        $this->addSql('DROP INDEX IDX_A348B683794BBE89');
        $this->addSql('DROP INDEX IDX_4214C246794BBE89');
        $this->addSql('DROP INDEX IDX_82C5714E794BBE89');
    }
}
