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

namespace App\Service\Storage;

use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\NameNormalizer;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\Media\MediaExtensions;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Storage\Exception\StoragePathConflictException;
use App\Service\Storage\Scan\LinkedCandidateResult;
use App\Service\Storage\Scan\ScanCandidate;
use App\Service\Storage\Scan\ScanResult;
use App\Service\Storage\Scan\ScanResultItem;
use App\Service\Storage\Search\SearchByPluginChain;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Scans a single Storage and matches its top-level files/folders against the catalog — the
 * service that connects the desktop.ini marker (StorageMarkerService, Таск 3 часть 1), name
 * cleaning (FilenameCleaner, часть 2), the local-catalog orphan search (OrphanAnimeMatcher,
 * часть 3) and the plugin search chain (SearchByPluginChain, часть 4) into one pure, synchronous
 * algorithm (Таск 3 часть 5), with an optional progress callback for the Messenger handler that
 * wraps it into a background job (ScanStorageMessageHandler, часть 6). No UI (часть 7) here —
 * this is the domain logic those layers call into.
 */
final class ScanStorageService
{
    public function __construct(
        private readonly StorageMarkerService $markerService,
        private readonly FilenameCleaner $filenameCleaner,
        private readonly OrphanAnimeMatcher $orphanMatcher,
        private readonly SearchByPluginChain $pluginChain,
        private readonly AnimeRepository $animeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly BulkFillerService $bulkFillerService,
        private readonly SyncTombstoneRepository $tombstoneRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param ?callable(int, int): void $onProgress called with (processed, total) after each
     *                                              top-level entry — lets the caller (the
     *                                              Messenger handler, Таск 3 часть 6) publish
     *                                              a percentage and refresh its job-lock heartbeat
     * @param ?string                   $atPath     the path $storage was actually found at, if that
     *                                              differs from Storage::getPath() — e.g. a reconnected
     *                                              external drive that came back under a different
     *                                              letter. When its desktop.ini marker still carries
     *                                              $storage's id (StorageMarkerService::relocateIfMarkerMoved(),
     *                                              issue #118 вопрос 9), $storage is relocated to $atPath
     *                                              before it is scanned, instead of leaving it stuck at
     *                                              its stale path
     */
    public function scan(Storage $storage, ?callable $onProgress = null, ?string $atPath = null): ScanResult
    {
        if (!$storage->getType()->isWritable() || $storage->getPath() === null) {
            return ScanResult::items([]);
        }

        if ($atPath !== null) {
            $this->markerService->relocateIfMarkerMoved($storage, $atPath);
        }

        if ($this->markerService->reconcile($storage) === StorageMarkerResult::Conflict) {
            return ScanResult::conflict();
        }

        $path = $storage->requirePath();

        /** @var array<string, Anime> $remainingLinked Anime::$storagePath => Anime, shrinks as files are matched */
        $remainingLinked = [];
        foreach ($this->animeRepository->findByStorage($storage) as $anime) {
            $remainingLinked[(string) $anime->getStoragePath()] = $anime;
        }

        $items = [];

        $entries = iterator_to_array($this->findTopLevelEntries($path), preserve_keys: false);
        $total = \count($entries);

        foreach ($entries as $index => $file) {
            $name = $file->getFilename();

            if (isset($remainingLinked[$name])) {
                $anime = $remainingLinked[$name];
                unset($remainingLinked[$name]);

                $checkedAt = $anime->getFilesCheckedAt();
                if ($checkedAt === null || $checkedAt->getTimestamp() < $file->getMTime()) {
                    $anime->markFilesChecked();
                    $items[] = ScanResultItem::updated($anime, $name);
                }
            } else {
                $items[] = $this->matchNewEntry($storage, $name);
            }

            if ($onProgress !== null) {
                $onProgress($index + 1, $total);
            }
        }

        foreach ($remainingLinked as $storagePath => $anime) {
            $items[] = ScanResultItem::filesMissing($anime, $storagePath);
        }

        $fileModified = filemtime($path);
        $storage->markScanned(new \DateTimeImmutable('@'.($fileModified !== false ? $fileModified : time())));

        // A per-item failure (see matchNewEntry()'s own try/catch, issue #832) that happened to
        // hit a genuine concurrent external-id-claim race can leave this EntityManager closed
        // (Doctrine's own reaction to any failed flush) even though the race itself was already
        // turned into a Conflict item rather than an exception. persist()/flush() both check
        // EntityManager::isOpen() first and throw otherwise — markScanned() above already
        // mutated the (still managed, still readable) $storage object either way, but this final
        // flush is skipped rather than letting that throw take the whole scan down after
        // everything else already succeeded; the storage's scanned timestamp simply does not
        // move this run and the self-correcting next scan picks it up again.
        if ($this->entityManager->isOpen()) {
            $this->entityManager->flush();
        } else {
            $this->logger->warning('Skipping the final scan flush: a concurrent create race during this scan already closed the EntityManager.', [
                'storage_id' => $storage->id,
            ]);
        }

        return ScanResult::items($items);
    }

    /** @return iterable<SplFileInfo> */
    private function findTopLevelEntries(string $path): iterable
    {
        $finder = (new Finder())
            ->in($path)
            ->ignoreUnreadableDirs()
            ->depth('== 0')
            ->notName('.*');

        foreach ($finder as $file) {
            if ($file->isFile() && !\in_array(strtolower($file->getExtension()), MediaExtensions::VIDEO, true)) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * Isolates whatever this single entry's processing throws (issue #832) — a plugin's
     * find()/findById(), the database, or anything else in the chain below — to this one item,
     * so the rest of the scan still runs and the caller still gets its final markScanned()+
     * flush(): the storage scan used to abort entirely (and, via ScanStorageMessageHandler's own
     * catch-all, report `scan.failed` with nothing shown at all) the moment a single top-level
     * entry's processing threw, which is exactly what a single plugin candidate whose externalId
     * already belonged to another catalog record used to do (see linkToChosenCandidate()'s
     * plugin branch and StoragePathConflictException below).
     */
    private function matchNewEntry(Storage $storage, string $name): ScanResultItem
    {
        $cleanedName = $this->filenameCleaner->clean($name);

        try {
            $orphans = $this->orphanMatcher->findCandidates($cleanedName);
            $pluginCandidates = $this->pluginChain->find($cleanedName);

            $candidates = $this->mergeCandidates($orphans, $pluginCandidates);

            return match (\count($candidates)) {
                0 => ScanResultItem::needsManualEntry($name, $cleanedName),
                1 => $this->isTombstoned($candidates[0])
                    ? ScanResultItem::needsConfirmation($name, $cleanedName, $candidates)
                    : $this->autoLinkOrConflict($storage, $name, $candidates[0]),
                default => ScanResultItem::needsConfirmation($name, $cleanedName, $candidates),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process a storage scan entry; marking it as an error and continuing with the rest of the scan.', [
                'storage_id' => $storage->id,
                'storage_path' => $name,
                'exception' => $e,
            ]);

            return ScanResultItem::error($name, $cleanedName, $e->getMessage());
        }
    }

    /**
     * Whether the candidate is a plugin match for a title the user deleted from the catalog (issue
     * #916). Such a folder is not linked on its own: linking would create the entry again, so the
     * user is asked instead. An orphan is a live record and is never tombstoned.
     */
    private function isTombstoned(ScanCandidate $candidate): bool
    {
        $plugin = $candidate->plugin;

        return $plugin !== null
            && $plugin->getExternalId() !== ''
            && $this->tombstoneRepository->exists($plugin->getPluginId(), $plugin->getExternalId());
    }

    /**
     * The 0/1/>1 rule's own count===1 case: exactly one candidate. A plugin candidate that
     * resolves (by pluginId/externalId) to a catalog record already linked to a different
     * storage path (issue #832) is reported as a Conflict item instead of letting
     * StoragePathConflictException escape to matchNewEntry()'s own catch-all — this is an
     * expected, common outcome (the same title found twice, from two different folders), not an
     * error.
     */
    private function autoLinkOrConflict(Storage $storage, string $name, ScanCandidate $candidate): ScanResultItem
    {
        try {
            return ScanResultItem::autoLinked($this->linkToChosenCandidate($storage, $name, $candidate), $name);
        } catch (StoragePathConflictException $e) {
            if ($e->anime !== null) {
                return ScanResultItem::conflict($e->anime, $name, $e->alreadyLinkedStoragePath ?? '');
            }

            throw $e;
        }
    }

    /**
     * Combines orphans and plugin matches into one candidate list, collapsing entries that name
     * the same title (by normalized name) into a single candidate instead of counting them as
     * two independent identifications. Two lone lookups that happen to each return one result
     * are not proof they found the same title on their own — agreement has to be checked
     * explicitly, which is what makes a genuinely ambiguous 2+ count meaningful downstream.
     *
     * @param list<Anime>                   $orphans
     * @param list<SearchByPluginCandidate> $pluginCandidates
     *
     * @return list<ScanCandidate>
     */
    private function mergeCandidates(array $orphans, array $pluginCandidates): array
    {
        $candidates = array_map(ScanCandidate::fromOrphan(...), $orphans);

        foreach ($pluginCandidates as $pluginCandidate) {
            $agreesWithExisting = false;
            foreach ($candidates as $candidate) {
                if ($this->candidateAgreesWithPlugin($candidate, $pluginCandidate)) {
                    $agreesWithExisting = true;
                    break;
                }
            }

            if (!$agreesWithExisting) {
                $candidates[] = ScanCandidate::fromPlugin($pluginCandidate);
            }
        }

        return $candidates;
    }

    private function candidateAgreesWithPlugin(ScanCandidate $candidate, SearchByPluginCandidate $plugin): bool
    {
        if ($candidate->orphan !== null) {
            return $this->orphanMatchesPluginCandidate($candidate->orphan, $plugin);
        }

        $existingPlugin = $candidate->plugin ?? throw new \LogicException('ScanCandidate must carry either an orphan or a plugin match');

        return NameNormalizer::normalize($existingPlugin->getName()) === NameNormalizer::normalize($plugin->getName());
    }

    /** Whether the plugin match names the same title as the orphan (its Anime::title or one of its AnimeName entries). */
    private function orphanMatchesPluginCandidate(Anime $orphan, SearchByPluginCandidate $plugin): bool
    {
        $normalizedPluginName = NameNormalizer::normalize($plugin->getName());

        if (NameNormalizer::normalize($orphan->getTitle()) === $normalizedPluginName) {
            return true;
        }

        foreach ($orphan->getNames() as $name) {
            if ($name->normalizedName === $normalizedPluginName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Binds $storagePath to $candidate: either of the two sources a ScanCandidate can carry
     * (see its docblock). Called both by the internal 0/1/>1 rule in matchNewEntry() when
     * exactly one candidate was found, and directly by the user-confirmation controller action
     * (issue #138, Таск 3 часть 7.3) when the scan reported ScanItemType::NeedsConfirmation and
     * the user picked one of the offered candidates — no re-run of that rule in that case.
     */
    public function linkToChosenCandidate(Storage $storage, string $storagePath, ScanCandidate $candidate): Anime
    {
        $orphan = $candidate->orphan;
        if ($orphan !== null) {
            $this->assertAnimeIsFreeToLink($orphan, $storage, $storagePath);
            $this->assertStoragePathIsFree($storage, $storagePath, $orphan);
            $orphan->setStorage($storage)->setStoragePath($storagePath);

            return $orphan;
        }

        $plugin = $candidate->plugin ?? throw new \LogicException('ScanCandidate must carry either an orphan or a plugin match');

        return $this->linkToChosenPluginCandidate($storage, $storagePath, $plugin)->anime;
    }

    /**
     * Same binding as {@see linkToChosenCandidate()}'s plugin branch, but also reports whether
     * $plugin's own data actually filled the created Anime in (issue #832, point 3 — the
     * storage-scan confirm endpoint needs this to tell the user "added without plugin data" when
     * it did not) — {@see linkToChosenCandidate()} itself only ever returns the Anime, which is
     * all the internal 0/1/>1 auto-link path (matchNewEntry()) needs.
     *
     * Goes through {@see BulkFillerService::findOrCreateFromPlugin()} (issue #832): checks
     * {@see AnimeRepository::resolve()} for an existing catalog record for
     * $plugin's (pluginId, externalId) *before* ever creating anything, so a candidate whose
     * external id already belongs to a catalog record linked elsewhere surfaces as a
     * StoragePathConflictException (caught in matchNewEntry()'s autoLinkOrConflict() during a
     * scan; left to the confirm controller otherwise) instead of hitting the create path's own
     * UNIQUE constraint and closing the EntityManager over it (issue #832's original bug: a
     * single plugin candidate whose externalId was already claimed used to abort the whole scan
     * this way).
     *
     * @param bool $downloadCoverSynchronously see {@see BulkFillerService::findOrCreateFromPlugin()} —
     *                                         true only for a single-candidate confirmation, not
     *                                         for a scan's own auto-link path
     */
    public function linkToChosenPluginCandidate(Storage $storage, string $storagePath, SearchByPluginCandidate $plugin, bool $downloadCoverSynchronously = false): LinkedCandidateResult
    {
        $this->assertStoragePathIsFree($storage, $storagePath, null);

        $externalId = $plugin->getExternalId();
        $result = null;

        try {
            $pluginId = new PluginId($plugin->getPluginId());

            if ($externalId !== '') {
                $result = $this->bulkFillerService->findOrCreateFromPlugin($pluginId, $externalId, $plugin->getName(), $downloadCoverSynchronously);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Plugin bulk-fill failed, falling back to a title-only placeholder.', [
                'pluginId' => $plugin->getPluginId(),
                'exception' => $e,
            ]);
        }

        if ($result === null) {
            $anime = (new TvAnime())->setTitle($plugin->getName())->setWatchStatus(WatchStatus::Plan);
            $this->entityManager->persist($anime);
            $anime->setStorage($storage)->setStoragePath($storagePath);

            return new LinkedCandidateResult($anime, filledFromPlugin: false);
        }

        $anime = $result->anime;
        if ($result->wasFound) {
            $this->assertAnimeIsFreeToLink($anime, $storage, $storagePath);
        }
        $anime->setStorage($storage)->setStoragePath($storagePath);

        return new LinkedCandidateResult($anime, $result->filledFromPlugin);
    }

    /**
     * Rejects a candidate Anime — an orphan, or (issue #832) a catalog record resolved by
     * (pluginId, externalId) — that is already linked to a storage_path other than the one
     * being requested (issue #147): the orphan matcher only ever returns Anime with both
     * $storage and $storagePath null (see AnimeRepository::findOrphanCandidatesByNormalizedName()),
     * so a non-null value here means another confirm request won the race between the scan
     * result being computed and the user's click — and a plugin-resolved record found by
     * {@see BulkFillerService::findOrCreateFromPlugin()} may simply already be in the catalog,
     * linked to an entirely different folder (the same title found twice). Re-confirming the
     * exact same storage/path the candidate already carries is treated as a harmless no-op
     * rather than a conflict.
     */
    private function assertAnimeIsFreeToLink(Anime $anime, Storage $storage, string $storagePath): void
    {
        if ($anime->getStorage() === null && $anime->getStoragePath() === null) {
            return;
        }

        if ($anime->getStorage()?->id === $storage->id && $anime->getStoragePath() === $storagePath) {
            return;
        }

        $alreadyLinkedPath = $anime->getStoragePath() ?? '';

        throw new StoragePathConflictException(\sprintf('Anime #%d is already linked to storage_path "%s" and cannot be re-linked to "%s".', $anime->id ?? 0, $alreadyLinkedPath, $storagePath), $anime, $alreadyLinkedPath);
    }

    /**
     * Rejects binding $storagePath when another Anime (other than $exclude, which covers the
     * idempotent re-confirm case above) is already linked to it within $storage (issue #147) —
     * the race where two confirm requests for the same file pick different candidates.
     */
    private function assertStoragePathIsFree(Storage $storage, string $storagePath, ?Anime $exclude): void
    {
        $existing = $this->animeRepository->findByStorageAndPath($storage, $storagePath);
        if ($existing === null || $existing === $exclude) {
            return;
        }

        throw new StoragePathConflictException(\sprintf('storage_path "%s" is already linked to Anime #%d.', $storagePath, $existing->id ?? 0));
    }
}
