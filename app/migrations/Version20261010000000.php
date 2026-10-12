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
 * Per-plugin sync host state (issue #1027): `needs_reauth` is set when a sync plugin's OAuth
 * session died and cleared by the next successful sync operation. A plain DBAL table, no entity;
 * a row is created before the flag is ever flipped (the transition is a conditional UPDATE).
 */
final class Version20261010000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create plugin_sync_state with the needs_reauth flag (issue #1027)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plugin_sync_state (
            plugin_id VARCHAR(64) NOT NULL,
            needs_reauth INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (plugin_id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plugin_sync_state');
    }
}
