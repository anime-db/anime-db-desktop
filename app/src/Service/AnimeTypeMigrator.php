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

namespace App\Service;

use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Infrastructure side of an Anime type migration: the actual field-copying and validation
 * live on Anime::migrate() (domain logic belongs on the entity, not here), so migration is
 * driven from the source entity itself ($source->migrate($targetType)) rather than a static
 * factory call; this class only wires that up to Doctrine.
 */
final class AnimeTypeMigrator
{
    private const MAX_MEDIA_RENAME_ATTEMPTS = 3;
    private const MEDIA_RENAME_RETRY_DELAY_MICROSECONDS = 100_000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $mediaDir,
    ) {
    }

    /**
     * @param AnimeType $targetType must differ from $source->getType()
     */
    public function migrate(Anime $source, AnimeType $targetType): Anime
    {
        $sourceId = $source->id;

        return $this->entityManager->wrapInTransaction(function () use ($source, $targetType, $sourceId): Anime {
            $target = $source->migrate($targetType);

            // Persist the copy (and its cascaded genres/names/images/sources) and flush it on
            // its own first, before removing the source: the target needs its own new
            // autoincrement id assigned (only known once this flush runs) so anime_plugin_data
            // rows can be repointed to it below, before the second flush's cascade delete of the
            // source row would otherwise take them with it.
            $this->entityManager->persist($target);
            $this->entityManager->flush();

            if ($sourceId !== null && $target->id !== null && $sourceId !== $target->id) {
                // anime_plugin_data isn't touched by Anime::migrate() above (unlike
                // genres/names/images/sources, it isn't part of the entity at all since issue
                // #299), so its rows still point at $sourceId here and would be lost to the
                // table's ON DELETE CASCADE once source is removed just below.
                $this->repointPluginData($sourceId, $target->id);

                // Same reasoning for the reconciliation snapshot (anime_sync_state, issue #365):
                // it isn't part of the Anime entity either, so a migration would otherwise leave
                // the new anime_id with no snapshot rows at all, making every sync participant
                // look "changed" on the very next reconciliation run (issue #365, "camp #13").
                $this->repointSyncState($sourceId, $target->id);
            }

            $this->entityManager->remove($source);
            $this->entityManager->flush();

            if ($sourceId !== null && $target->id !== null && $sourceId !== $target->id) {
                // The migrated entity got a new autoincrement id, so its media directory
                // (%AppData%/media/{id}/) has to move along with it. This runs inside the
                // same transaction as the entity swap above: if the move can't be completed
                // after retries, the exception below rolls the whole migration back instead
                // of leaving the new entity pointing at a media directory that doesn't exist.
                $this->renameMediaDirectory($sourceId, $target->id);
            }

            return $target;
        });
    }

    private function repointPluginData(int $sourceId, int $targetId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE anime_plugin_data SET anime_id = ? WHERE anime_id = ?',
            [$targetId, $sourceId],
        );
    }

    private function repointSyncState(int $sourceId, int $targetId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE anime_sync_state SET anime_id = ? WHERE anime_id = ?',
            [$targetId, $sourceId],
        );
    }

    private function renameMediaDirectory(int $sourceId, int $targetId): void
    {
        $sourceDir = rtrim($this->mediaDir, '/\\').'/'.$sourceId;
        if (!is_dir($sourceDir)) {
            // Anime without a cover/images never got a media directory in the first place.
            return;
        }

        $targetDir = rtrim($this->mediaDir, '/\\').'/'.$targetId;

        for ($attempt = 1; $attempt <= self::MAX_MEDIA_RENAME_ATTEMPTS; ++$attempt) {
            if (@rename($sourceDir, $targetDir)) {
                return;
            }

            if ($attempt < self::MAX_MEDIA_RENAME_ATTEMPTS) {
                usleep(self::MEDIA_RENAME_RETRY_DELAY_MICROSECONDS);
            }
        }

        throw new \RuntimeException(sprintf('Failed to move anime media directory from "%s" to "%s" after %d attempts.', $sourceDir, $targetDir, self::MAX_MEDIA_RENAME_ATTEMPTS));
    }
}
