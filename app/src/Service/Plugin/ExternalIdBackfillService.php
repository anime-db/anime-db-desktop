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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Entity\AnimeSource;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Service\JobLock\JobLockService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Catalog sweep (issue #258) that resolves and caches a sync plugin's external id for every Anime that already carries a matching source URL, via
 * {@see Anime::getExternalId()} (issue #211) — instead of leaving it to be resolved lazily,
 * one record at a time, the first time pull-dedup (issue #215a) or cross-vendor dedup
 * (issue #216) needs it.
 *
 * Guarded by {@see JobLockService} under `sync_backfill:<pluginId>`, same convention as
 * ScanStorageMessageHandler's per-storage lock: two overlapping backfills of the same
 * plugin (e.g. the message got redelivered) would otherwise race on the same rows for no
 * benefit. A skipped run is not an error: the sweep that already holds the lock does the same work.
 *
 * Called synchronously at the start of {@see \App\MessageHandler\SyncSeedMessageHandler}, before
 * the pull (issue #867): the pull's indexByExternalId() only sees ids that are already cached, so
 * running the sweep after (or concurrently with) it would create second rows for titles that
 * are in the catalog with a source URL.
 *
 * The catalog is walked page by page via {@see AnimeRepository::findPage()} (same
 * LIMIT/OFFSET + EntityManager::clear() pattern as AnimeReindexService::reindexAll(), for the
 * same reason: never materialize the whole catalog in memory at once) rather than filtered
 * down to only-unresolved rows in SQL: a plain full-table walk keeps pagination correct
 * regardless of how many rows this batch resolves (removing rows from the WHERE clause
 * mid-walk would shift the OFFSET under a fixed-size page). Rows that already carry a cached
 * id for this plugin are skipped without calling resolveExternalId() again. A single
 * record's resolveExternalId() throwing is
 * logged and skipped, not fatal for the rest of the sweep; each page is flushed and the lock
 * heartbeat is refreshed before moving to the next one, so an interrupted run (crash, app
 * closed) never loses more than one page of progress and a re-dispatch of the same message
 * simply resumes — already-resolved rows are skipped again, nothing is double-processed.
 */
final class ExternalIdBackfillService
{
    private const int PAGE_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnimeRepository $animeRepository,
        private readonly JobLockService $jobLockService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function backfill(PluginId $pluginId, SyncInterface $sync): void
    {
        $jobKey = \sprintf('sync_backfill:%s', $pluginId);
        $lockAcquired = false;

        try {
            if (!$this->jobLockService->acquire($jobKey)) {
                $this->logger->info('Skipping external id backfill: already running for this plugin.', [
                    'plugin_id' => (string) $pluginId,
                    'job_key' => $jobKey,
                ]);

                return;
            }
            $lockAcquired = true;

            // (plugin_id, external_id) is UNIQUE: a record whose resolved id is already held by
            // another one must be skipped, not flushed — a constraint violation would close the
            // EntityManager and take the whole seed down with it.
            $taken = $this->animeRepository->findCachedExternalIds($pluginId);

            $processed = 0;
            $set = 0;
            $skipped = 0;
            $offset = 0;

            do {
                $page = $this->animeRepository->findPage($offset, self::PAGE_SIZE);

                foreach ($page as $anime) {
                    if ($anime->getCachedExternalId($pluginId) !== null) {
                        continue;
                    }

                    ++$processed;

                    try {
                        $externalId = $sync->resolveExternalId(
                            array_map(static fn (AnimeSource $source): string => $source->url, $anime->getSources()->toArray()),
                        );
                    } catch (\Throwable $exception) {
                        $this->logger->error('Skipping anime during external id backfill: resolveExternalId() failed.', [
                            'plugin_id' => (string) $pluginId,
                            'anime_id' => $anime->id,
                            'exception' => $exception,
                        ]);

                        continue;
                    }

                    if ($externalId === null) {
                        ++$skipped;

                        continue;
                    }

                    if (isset($taken[$externalId])) {
                        $this->logger->warning('Skipping anime during external id backfill: external id is already held by another record.', [
                            'plugin_id' => (string) $pluginId,
                            'anime_id' => $anime->id,
                            'external_id' => $externalId,
                        ]);
                        ++$skipped;

                        continue;
                    }

                    $anime->rememberExternalId($pluginId, $externalId);
                    $taken[$externalId] = true;
                    ++$set;
                }

                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->jobLockService->heartbeat($jobKey);

                $offset += self::PAGE_SIZE;
            } while (\count($page) === self::PAGE_SIZE);

            $this->logger->info('External id backfill finished.', [
                'plugin_id' => (string) $pluginId,
                'processed' => $processed,
                'set' => $set,
                'skipped' => $skipped,
            ]);
        } finally {
            if ($lockAcquired) {
                $this->jobLockService->release($jobKey);
            }
        }
    }
}
