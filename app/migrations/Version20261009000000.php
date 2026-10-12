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
 * Notification history (issue #1023). `kind`, `severity` and `subject_type` carry no CHECK: the
 * set of values grows with the code and a stored value must stay readable. The partial unique
 * index is the merge key of unread records; `notification_once_key` holds the keys of the `once`
 * policy apart from the records, so retention of the records does not release them (it is written
 * through DBAL only, without an entity).
 */
final class Version20261009000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification and notification_once_key (issue #1023)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            kind VARCHAR(64) NOT NULL,
            severity VARCHAR(16) NOT NULL,
            params CLOB NOT NULL,
            subject_type VARCHAR(32) DEFAULT NULL,
            subject_id VARCHAR(64) DEFAULT NULL,
            occurred_at INTEGER NOT NULL,
            read_at INTEGER DEFAULT NULL,
            occurrences INTEGER NOT NULL,
            dedup_key VARCHAR(255) DEFAULT NULL
        )');
        $this->addSql('CREATE INDEX IDX_NOTIFICATION_OCCURRED_AT ON notification (occurred_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_NOTIFICATION_DEDUP_UNREAD ON notification (dedup_key) WHERE (read_at IS NULL)');
        $this->addSql('CREATE TABLE notification_once_key (
            dedup_key VARCHAR(255) NOT NULL,
            created_at INTEGER NOT NULL,
            PRIMARY KEY (dedup_key)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_once_key');
        $this->addSql('DROP INDEX UNIQ_NOTIFICATION_DEDUP_UNREAD');
        $this->addSql('DROP INDEX IDX_NOTIFICATION_OCCURRED_AT');
        $this->addSql('DROP TABLE notification');
    }
}
