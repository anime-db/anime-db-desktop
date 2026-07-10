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
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Service\Storage\Scan\ScanCandidate;
use App\Service\Storage\Scan\ScanResult;
use App\Service\Storage\Scan\ScanResultItem;
use App\Service\Storage\Search\SearchByPluginChain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Scans a single Storage and matches its top-level files/folders against the catalog — the
 * service that connects the desktop.ini marker (StorageMarkerService, Таск 3 часть 1), name
 * cleaning (FilenameCleaner, часть 2), the local-catalog orphan search (OrphanAnimeMatcher,
 * часть 3) and the plugin search chain (SearchByPluginChain, часть 4) into one pure, synchronous
 * algorithm (Таск 3 часть 5). No Messenger/progress (часть 6) and no UI (часть 7) here — this is
 * the domain logic those layers call into.
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

    public function scan(Storage $storage): ScanResult
    {
        if (!\in_array($storage->getType(), self::SCANNABLE_TYPES, true)) {
            return ScanResult::items([]);
        }

        if (StorageMarkerResult::Conflict === $this->markerService->reconcile($storage)) {
            return ScanResult::conflict();
        }

        $path = $storage->getPath();

        /** @var array<string, Anime> $remainingLinked Anime::$storagePath => Anime, shrinks as files are matched */
        $remainingLinked = [];
        foreach ($this->animeRepository->findByStorage($storage) as $anime) {
            $remainingLinked[(string) $anime->getStoragePath()] = $anime;
        }

        $items = [];

        foreach ($this->findTopLevelEntries($path) as $file) {
            $name = $file->getFilename();

            if (isset($remainingLinked[$name])) {
                $anime = $remainingLinked[$name];
                unset($remainingLinked[$name]);

                if ($anime->getDateUpdate()->getTimestamp() < $file->getMTime()) {
                    $items[] = ScanResultItem::updated($anime, $name);
                }

                continue;
            }

            $items[] = $this->matchNewEntry($storage, $name);
        }

        foreach ($remainingLinked as $storagePath => $anime) {
            $items[] = ScanResultItem::filesMissing($anime, $storagePath);
        }

        $fileModified = filemtime($path);
        $storage->markScanned(new \DateTimeImmutable('@'.(false !== $fileModified ? $fileModified : time())));
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

        $candidates = array_map(
            ScanCandidate::fromOrphan(...),
            $this->orphanMatcher->findCandidates($cleanedName),
        );

        $pluginCandidate = $this->pluginChain->find($cleanedName);
        if (null !== $pluginCandidate) {
            $candidates[] = ScanCandidate::fromPlugin($pluginCandidate);
        }

        return match (\count($candidates)) {
            0 => ScanResultItem::needsManualEntry($name, $cleanedName),
            1 => ScanResultItem::autoLinked($this->autoLink($storage, $name, $candidates[0]), $name),
            default => ScanResultItem::needsConfirmation($name, $cleanedName, $candidates),
        };
    }

    private function autoLink(Storage $storage, string $name, ScanCandidate $candidate): Anime
    {
        $orphan = $candidate->orphan;
        if (null !== $orphan) {
            $orphan->setStorage($storage)->setStoragePath($name);

            return $orphan;
        }

        $plugin = $candidate->plugin ?? throw new \LogicException('ScanCandidate must carry either an orphan or a plugin match');

        // Stage 4 plugins don't exist yet (SearchByPluginChain currently always resolves to
        // NullSearchByPlugin), so a plugin candidate only ever carries a name — not enough to
        // pick a concrete AnimeType. TvAnime is the placeholder default until a real plugin
        // implementation can report the type it found.
        $anime = new TvAnime();
        $anime->setTitle($plugin->name)
            ->setWatchStatus(WatchStatus::Plan)
            ->setStorage($storage)
            ->setStoragePath($name);
        $this->entityManager->persist($anime);

        return $anime;
    }
}
