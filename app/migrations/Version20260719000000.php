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

final class Version20260719000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sync_review_item table (issue #267) — persistent "needs review" list a sync run '
            .'can raise, surviving past the run until a user resolves it.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE sync_review_item (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            kind VARCHAR(32) NOT NULL,
            payload CLOB NOT NULL,
            created_at INTEGER NOT NULL,
            resolved_at INTEGER DEFAULT NULL
        )");
        $this->addSql('CREATE INDEX IDX_SYNC_REVIEW_ITEM_RESOLVED_AT ON sync_review_item (resolved_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_SYNC_REVIEW_ITEM_RESOLVED_AT');
        $this->addSql('DROP TABLE sync_review_item');
    }
}
