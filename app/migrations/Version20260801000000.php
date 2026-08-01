<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anime_external_id table (issue #297) as a persistent, indexed replacement for '
            .'metadata[\'external_id\'][pluginId] — migrates existing rows out of the JSON blob and '
            .'drops that key from metadata afterwards.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anime_external_id (
            anime_id INTEGER NOT NULL,
            plugin_id VARCHAR(64) NOT NULL,
            external_id VARCHAR(255) NOT NULL,
            PRIMARY KEY (anime_id, plugin_id),
            CONSTRAINT FK_ANIME_EXTERNAL_ID_ANIME FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ANIME_EXTERNAL_ID_PLUGIN_EXTERNAL ON anime_external_id (plugin_id, external_id)');

        // Backfill from the JSON blob: json_each() is only invoked against rows that already
        // passed the metadata/external_id-present filter in the subquery below, rather than in
        // a WHERE clause on the outer join — SQLite evaluates a correlated table-valued
        // function per outer row regardless of a later WHERE, so calling it against a NULL or
        // key-less metadata column here would error instead of yielding zero rows.
        //
        // OR IGNORE: the old JSON-blob model never enforced UNIQUE(plugin_id, external_id)
        // across anime rows — two anime could carry the same (plugin_id, external_id) pair
        // (manual duplicate entry, a pre-dedup/pre-idempotent-pull-sync row, ...). The new
        // index does enforce it, so a straight INSERT would abort the whole migration on any
        // such pre-existing duplicate, breaking the upgrade with no recovery. OR IGNORE instead
        // deterministically keeps the mapping for the lowest anime.id (the subquery's natural
        // row order) and drops the conflicting duplicate's external_id — an acceptable, silent
        // loss for what was already an unenforced, likely-accidental duplicate.
        $this->addSql("INSERT OR IGNORE INTO anime_external_id (anime_id, plugin_id, external_id)
            SELECT a.id, je.key, je.value
            FROM (
                SELECT id, metadata FROM anime
                WHERE metadata IS NOT NULL AND json_extract(metadata, '$.external_id') IS NOT NULL
            ) a, json_each(a.metadata, '$.external_id') AS je");

        $this->addSql("UPDATE anime SET metadata = json_remove(metadata, '$.external_id')
            WHERE metadata IS NOT NULL AND json_extract(metadata, '$.external_id') IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE anime
            SET metadata = json_set(COALESCE(metadata, '{}'), '$.external_id', (
                SELECT json_group_object(plugin_id, external_id)
                FROM anime_external_id
                WHERE anime_external_id.anime_id = anime.id
            ))
            WHERE EXISTS (SELECT 1 FROM anime_external_id WHERE anime_external_id.anime_id = anime.id)");

        $this->addSql('DROP INDEX UNIQ_ANIME_EXTERNAL_ID_PLUGIN_EXTERNAL');
        $this->addSql('DROP TABLE anime_external_id');
    }
}
