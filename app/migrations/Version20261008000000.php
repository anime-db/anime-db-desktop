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
 * Storage scan journal (issue #998): the last runs of every storage with their outcome. `counts`
 * and `items` are JSON documents (see ScanRunJournal); `finished_at` stays NULL while the run is
 * going. The table has no entity — it is written and read through DBAL only.
 */
final class Version20261008000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create scan_run, the storage scan journal (issue #998)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scan_run (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            storage_id INTEGER NOT NULL,
            started_at INTEGER NOT NULL,
            finished_at INTEGER DEFAULT NULL,
            status VARCHAR(16) NOT NULL CHECK (status IN (\'running\', \'done\', \'failed\', \'marker_conflict\')),
            error_message CLOB DEFAULT NULL,
            counts CLOB NOT NULL,
            items CLOB NOT NULL,
            CONSTRAINT FK_SCAN_RUN_STORAGE FOREIGN KEY (storage_id) REFERENCES storage (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_SCAN_RUN_STORAGE ON scan_run (storage_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scan_run');
    }
}
