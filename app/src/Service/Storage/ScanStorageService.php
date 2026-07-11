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

namespace App\Service\Storage;

use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\NameNormalizer;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Service\Storage\Exception\StoragePathConflictException;
use App\Service\Storage\Scan\ScanCandidate;
use App\Service\Storage\Scan\ScanResult;
use App\Service\Storage\Scan\ScanResultItem;
use App\Service\Storage\Search\SearchByPluginCandidate;
use App\Service\Storage\Search\SearchByPluginChain;
use Doctrine\ORM\EntityManagerInterface;
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
    /** @var list<StorageType> */
    private const SCANNABLE_TYPES = [StorageType::Folder, StorageType::External];

    public function __construct(
        private readonly StorageMarkerService $markerService,
        private readonly FilenameCleaner $filenameCleaner,
        private readonly OrphanAnimeMatcher $orphanMatcher,
        private readonly SearchByPluginChain $pluginChain,
        private readonly AnimeRepository $animeRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param ?callable(int, int): void $onProgress called with (processed, total) after each
     *                                              top-level entry — lets the caller (the
     *                                              Messenger handler, Таск 3 часть 6) publish
     *                                              a percentage and refresh its job-lock heartbeat
     */
    public function scan(Storage $storage, ?callable $onProgress = null): ScanResult
    {
        if (!\in_array($storage->getType(), self::SCANNABLE_TYPES, true)) {
            return ScanResult::items([]);
        }

        if ($this->markerService->reconcile($storage) === StorageMarkerResult::Conflict) {
            return ScanResult::conflict();
        }

        $path = $storage->getPath();

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

                if ($anime->getDateUpdate()->getTimestamp() < $file->getMTime()) {
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
        $this->entityManager->flush();

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
            if ($file->isFile() && !\in_array(strtolower($file->getExtension()), FilenameCleaner::EXTENSIONS, true)) {
                continue;
            }

            yield $file;
        }
    }

    private function matchNewEntry(Storage $storage, string $name): ScanResultItem
    {
        $cleanedName = $this->filenameCleaner->clean($name);

        $orphans = $this->orphanMatcher->findCandidates($cleanedName);
        $pluginCandidates = $this->pluginChain->find($cleanedName);

        $candidates = $this->mergeCandidates($orphans, $pluginCandidates);

        return match (\count($candidates)) {
            0 => ScanResultItem::needsManualEntry($name, $cleanedName),
            1 => ScanResultItem::autoLinked($this->linkToChosenCandidate($storage, $name, $candidates[0]), $name),
            default => ScanResultItem::needsConfirmation($name, $cleanedName, $candidates),
        };
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

        return NameNormalizer::normalize($existingPlugin->name) === NameNormalizer::normalize($plugin->name);
    }

    /** Whether the plugin match names the same title as the orphan (its Anime::title or one of its AnimeName entries). */
    private function orphanMatchesPluginCandidate(Anime $orphan, SearchByPluginCandidate $plugin): bool
    {
        $normalizedPluginName = NameNormalizer::normalize($plugin->name);

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
            $this->assertOrphanIsFreeToLink($orphan, $storage, $storagePath);
            $this->assertStoragePathIsFree($storage, $storagePath, $orphan);
            $orphan->setStorage($storage)->setStoragePath($storagePath);

            return $orphan;
        }

        $this->assertStoragePathIsFree($storage, $storagePath, null);

        $plugin = $candidate->plugin ?? throw new \LogicException('ScanCandidate must carry either an orphan or a plugin match');

        // Stage 4 plugins don't exist yet (SearchByPluginChain currently always resolves to
        // NullSearchByPlugin), so a plugin candidate only ever carries a name — not enough to
        // pick a concrete AnimeType. TvAnime is the placeholder default until a real plugin
        // implementation can report the type it found.
        $anime = new TvAnime();
        $anime->setTitle($plugin->name)
            ->setWatchStatus(WatchStatus::Plan)
            ->setStorage($storage)
            ->setStoragePath($storagePath);
        $this->entityManager->persist($anime);

        return $anime;
    }

    /**
     * Rejects a candidate orphan that is already linked to a storage_path other than the one
     * being requested (issue #147): the orphan matcher only ever returns Anime with both
     * $storage and $storagePath null (see AnimeRepository::findOrphanCandidatesByNormalizedName()),
     * so a non-null value here means another confirm request won the race between the scan
     * result being computed and the user's click. Re-confirming the exact same storage/path
     * the orphan already carries is treated as a harmless no-op rather than a conflict.
     */
    private function assertOrphanIsFreeToLink(Anime $orphan, Storage $storage, string $storagePath): void
    {
        if ($orphan->getStorage() === null && $orphan->getStoragePath() === null) {
            return;
        }

        if ($orphan->getStorage()?->id === $storage->id && $orphan->getStoragePath() === $storagePath) {
            return;
        }

        throw new StoragePathConflictException(\sprintf('Anime #%d is already linked to storage_path "%s" and cannot be re-linked to "%s".', $orphan->id ?? 0, $orphan->getStoragePath() ?? '', $storagePath));
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
