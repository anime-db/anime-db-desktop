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
 * Description origin (issue #1068): `anime_description.source` holds the plugin id that wrote the
 * text, `manual` for a text the user changed in the edit form, or NULL when unknown. Existing rows
 * get NULL — their origin cannot be recovered offline.
 */
final class Version20261013000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anime_description.source (issue #1068)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anime_description ADD COLUMN source VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anime_description DROP COLUMN source');
    }
}
