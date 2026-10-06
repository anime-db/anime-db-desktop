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
 * Creates sync_tombstone (issue #916): one row per (plugin_id, external_id) of a catalog entry the
 * user deleted locally. A later pull or storage scan checks it so the same source title is not
 * created again. No foreign key to anime on purpose: the entry is gone, the row must outlive it.
 */
final class Version20261005000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sync_tombstone (issue #916)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sync_tombstone (
            plugin_id VARCHAR(64) NOT NULL,
            external_id VARCHAR(255) NOT NULL,
            deleted_at INTEGER NOT NULL,
            PRIMARY KEY (plugin_id, external_id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sync_tombstone');
    }
}
