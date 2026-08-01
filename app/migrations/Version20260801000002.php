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
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `anime_plugin_data` (issue #299) and migrates every `metadata['plugins'][pluginId]` slice
 * off the shared `anime.metadata` JSON column into one row per (anime, plugin) there, then strips
 * the `plugins` key from `metadata`. See {@see \App\Entity\AnimePluginData} for why: Doctrine
 * writes `metadata` whole (no partial-JSON-update), so two background flows (sync/scan/download)
 * writing different plugins' data for the same anime at the same time could silently lose one
 * write. A row per (anime, plugin) removes that collision entirely for different plugins, and
 * adds row-level optimistic locking (`version`) for the same (anime, plugin) pair written twice
 * at once.
 */
final class Version20260801000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add anime_plugin_data table and migrate metadata[plugins][pluginId] into it (issue #299)';
    }

    public function up(Schema $schema): void
    {
        // Read the current data *before* queuing any DDL/DML below: addSql() only queues
        // statements to run, in order, after this method returns, so the migrated values have to
        // be computed from a fetch that runs immediately, against the pre-migration table.
        $rows = $this->connection->fetchAllAssociative('SELECT id, metadata FROM anime WHERE metadata IS NOT NULL');

        $this->addSql("CREATE TABLE anime_plugin_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            anime_id INTEGER NOT NULL,
            plugin_id VARCHAR(128) NOT NULL,
            payload CLOB NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (anime_id) REFERENCES anime (id) ON DELETE CASCADE
        )");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ANIME_PLUGIN_DATA_ANIME_PLUGIN ON anime_plugin_data (anime_id, plugin_id)');

        foreach ($rows as $row) {
            $animeId = (int) $row['id'];
            $metadata = json_decode((string) $row['metadata'], true, flags: \JSON_THROW_ON_ERROR);
            if (!\is_array($metadata) || !isset($metadata['plugins']) || !\is_array($metadata['plugins'])) {
                continue;
            }

            $plugins = $metadata['plugins'];
            unset($metadata['plugins']);

            foreach ($plugins as $pluginId => $payload) {
                if (!\is_array($payload)) {
                    continue;
                }

                $this->addSql(
                    'INSERT INTO anime_plugin_data (anime_id, plugin_id, payload, version) VALUES (?, ?, ?, 1)',
                    [$animeId, (string) $pluginId, json_encode($payload, \JSON_THROW_ON_ERROR)],
                    [Types::INTEGER, Types::STRING, Types::STRING],
                );
            }

            $this->addSql(
                'UPDATE anime SET metadata = ? WHERE id = ?',
                [$metadata === [] ? null : json_encode($metadata, \JSON_THROW_ON_ERROR), $animeId],
                [Types::STRING, Types::INTEGER],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id AS anime_id, a.metadata AS metadata, p.plugin_id AS plugin_id, p.payload AS payload
             FROM anime_plugin_data p JOIN anime a ON a.id = p.anime_id',
        );

        /** @var array<int, array{metadata: array<string, mixed>, plugins: array<string, mixed>}> $byAnime */
        $byAnime = [];
        foreach ($rows as $row) {
            $animeId = (int) $row['anime_id'];
            if (!isset($byAnime[$animeId])) {
                $metadata = $row['metadata'] === null ? [] : json_decode((string) $row['metadata'], true, flags: \JSON_THROW_ON_ERROR);
                $byAnime[$animeId] = ['metadata' => \is_array($metadata) ? $metadata : [], 'plugins' => []];
            }

            $byAnime[$animeId]['plugins'][$row['plugin_id']] = json_decode((string) $row['payload'], true, flags: \JSON_THROW_ON_ERROR);
        }

        foreach ($byAnime as $animeId => $data) {
            $metadata = [...$data['metadata'], 'plugins' => $data['plugins']];
            $this->addSql(
                'UPDATE anime SET metadata = ? WHERE id = ?',
                [json_encode($metadata, \JSON_THROW_ON_ERROR), $animeId],
                [Types::STRING, Types::INTEGER],
            );
        }

        $this->addSql('DROP INDEX UNIQ_ANIME_PLUGIN_DATA_ANIME_PLUGIN');
        $this->addSql('DROP TABLE anime_plugin_data');
    }
}
