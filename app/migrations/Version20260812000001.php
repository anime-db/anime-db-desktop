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
 * Creates anime_sync_state (issue #365): one row per (anime, participant) — "participant" is
 * either a plugin id or the literal "local" — the reconciliation snapshot a future sync engine
 * diffs the current (watchStatus, watchedEpisodes) projection against to detect who changed. No
 * CHECK constraint on last_status (see App\Entity\Enum\WatchStatus): SQLite can't ALTER one, so
 * enumType already validates it at the application boundary, same pattern as anime.watch_status
 * did not use here on purpose — this table is written to only by future sync-engine code, not by
 * hand-authored SQL, so the extra CHECK this project's other enum columns carry is skipped.
 */
final class Version20260812000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create anime_sync_state table: per-participant last-seen watch progress snapshot for sync reconciliation (issue #365)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anime_sync_state (
            anime_id INTEGER NOT NULL,
            participant_id VARCHAR(64) NOT NULL,
            last_status VARCHAR(16) NOT NULL,
            last_watched_episodes INTEGER DEFAULT NULL,
            last_updated_at INTEGER NOT NULL,
            PRIMARY KEY (anime_id, participant_id),
            CONSTRAINT FK_ANIME_SYNC_STATE_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE anime_sync_state');
    }
}
