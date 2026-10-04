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

/**
 * Adds anime_sync_state.push_pending (issue #862) and creates pending_sync_push: a failed
 * forward-propagation push to a sync participant must be retried on this anime's next
 * reconciliation even if nothing else changed, and a plain bool column on the existing snapshot
 * row cannot represent that retry need for a participant that has no row yet (see
 * App\Entity\PendingSyncPush). Every existing anime_sync_state row defaults to push_pending = 0
 * ("no marker") — there is no history of failed pushes to backfill, since the marker did not
 * exist before this migration.
 */
final class Version20261004000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anime_sync_state.push_pending and create pending_sync_push (issue #862)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE anime_sync_state ADD COLUMN push_pending BOOLEAN DEFAULT 0 NOT NULL');

        $this->addSql('CREATE TABLE pending_sync_push (
            anime_id INTEGER NOT NULL,
            participant_id VARCHAR(64) NOT NULL,
            PRIMARY KEY (anime_id, participant_id),
            CONSTRAINT FK_PENDING_SYNC_PUSH_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IDX_466FADA0794BBE89 ON pending_sync_push (anime_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pending_sync_push');

        // SQLite has no DROP COLUMN before 3.35 (bundled libsqlite3 in FrankenPHP's PHP build is
        // not guaranteed >= 3.35) — full table rebuild, same pattern as other
        // irreversible-by-simple-SQL migrations in this project (see gotchas.md).
        $this->addSql('CREATE TABLE anime_sync_state__new (
            anime_id INTEGER NOT NULL,
            participant_id VARCHAR(64) NOT NULL,
            last_status VARCHAR(16) NOT NULL,
            last_watched_episodes INTEGER DEFAULT NULL,
            last_updated_at INTEGER NOT NULL,
            PRIMARY KEY (anime_id, participant_id),
            CONSTRAINT FK_ANIME_SYNC_STATE_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO anime_sync_state__new (anime_id, participant_id, last_status, last_watched_episodes, last_updated_at)
            SELECT anime_id, participant_id, last_status, last_watched_episodes, last_updated_at FROM anime_sync_state');
        $this->addSql('DROP TABLE anime_sync_state');
        $this->addSql('ALTER TABLE anime_sync_state__new RENAME TO anime_sync_state');
        $this->addSql('CREATE INDEX IDX_4214C246794BBE89 ON anime_sync_state (anime_id)');
    }
}
