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

final class Version20260711000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a unique index on anime (storage_id, storage_path): last line of defense against '
            .'linking two Anime to the same storage file (issue #147) — ScanStorageService::linkToChosenCandidate() '
            .'already rejects this at the application level, this only guards against a bypass. '
            .'SQLite treats each NULL as distinct in a UNIQUE index, so unlinked anime (both columns NULL) are unaffected.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ANIME_STORAGE_STORAGE_PATH ON anime (storage_id, storage_path)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_ANIME_STORAGE_STORAGE_PATH');
    }
}
