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

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Entity\Exception\InvalidPathException;
use App\Entity\Storage;
use App\Message\ScanStorageMessage;
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Service\JobLock\JobLockService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Binds an entry to a top-level item of a storage by a path the user picked (issue #997). The
 * data model stays the same: the link is the pair Anime::$storage + Anime::$storagePath, where
 * storagePath is the NAME of the top-level item inside the storage root, never a path. A path
 * deeper than the top level is lifted to its top-level folder.
 *
 * The steps of {@see self::link()} run in order and the first refusal stops the processing.
 */
final class ManualLinkService
{
    public function __construct(
        private readonly AnimeRepository $animes,
        private readonly DownloadRepository $downloads,
        private readonly StorageLocator $locator,
        private readonly StorageMarkerService $markerService,
        private readonly StorageIconService $iconService,
        private readonly JobLockService $jobLock,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * $relocateStorageId is the confirmation of an earlier {@see ManualLinkStatus::RelocateRequired}
     * answer: the storage with that id is moved to where its marker was found, then the link is made.
     */
    public function link(Anime $anime, string $selectedPath, ?int $relocateStorageId = null): ManualLinkResult
    {
        $location = $this->locator->locate($selectedPath);
        if ($location->kind === StorageLocationKind::InvalidPath) {
            return ManualLinkResult::refused(ManualLinkStatus::InvalidPath);
        }
        if ($location->kind === StorageLocationKind::Outside) {
            return ManualLinkResult::outsideStorages($location->suggestedStoragePath ?? trim($selectedPath));
        }

        $storage = $location->storage ?? throw new \LogicException('A found location always names its storage.');
        $rootPath = $location->rootPath ?? throw new \LogicException('A found location always has a root.');
        $path = $location->path ?? '';
        $relocate = $location->relocated;
        $normalizedRoot = $location->normalizedRoot();
        if ($location->isRoot()) {
            return ManualLinkResult::refused(ManualLinkStatus::StorageRoot, $storage);
        }

        $storageId = $storage->id ?? throw new \LogicException('A storage must be persisted before an entry can be linked to it.');
        if ($this->jobLock->isLocked(ScanStorageMessage::jobKey($storageId))) {
            return ManualLinkResult::refused(ManualLinkStatus::ScanRunning, $storage);
        }

        if (!$location->isWithinRoot()) {
            return ManualLinkResult::refused(ManualLinkStatus::InvalidPath, $storage);
        }

        $relative = ltrim(substr($path, \strlen($normalizedRoot)), '\\');
        $requestedName = explode('\\', $relative, 2)[0];
        $nested = $relative !== $requestedName;

        $names = $this->listRoot($rootPath);
        if ($names === null) {
            return ManualLinkResult::refused(ManualLinkStatus::EntryNotFound, $storage, $requestedName);
        }

        $name = $this->resolveName($requestedName, $names);
        if ($name === false) {
            return ManualLinkResult::refused(ManualLinkStatus::AmbiguousName, $storage, $requestedName);
        }
        if ($name === null) {
            return ManualLinkResult::refused(ManualLinkStatus::EntryNotFound, $storage, $requestedName);
        }

        $entry = new \SplFileInfo(rtrim($rootPath, '\\/').\DIRECTORY_SEPARATOR.$name);
        if (!$entry->isReadable()) {
            return ManualLinkResult::refused(ManualLinkStatus::EntryNotFound, $storage, $name);
        }
        if (!TopLevelEntry::isVisibleToScanner($entry)) {
            return ManualLinkResult::refused(ManualLinkStatus::EntryNotVisible, $storage, $name);
        }

        // Not ScanStorageService::assertAnimeIsFreeToLink(): re-binding an entry that is already
        // linked is an explicit user action here.
        $holder = $this->findHolder($storage, $name, $anime);
        if ($holder !== null) {
            return ManualLinkResult::occupied($storage, $name, $holder);
        }

        if ($relocate) {
            // Same guard as StorageEditController: the torrent client still writes into the old root.
            if ($this->downloads->hasUnfinishedDownloadsForTargetStorage($storageId)) {
                return ManualLinkResult::refused(ManualLinkStatus::StorageHasDownloads, $storage);
            }

            if ($relocateStorageId !== $storage->id) {
                return ManualLinkResult::relocateRequired($storage, $rootPath);
            }

            $previousPath = $storage->requirePath();
            try {
                $storage->relocate($this->locator->absoluteRoot($rootPath));
            } catch (InvalidPathException) {
                return ManualLinkResult::refused(ManualLinkStatus::InvalidPath, $storage);
            }
            $this->iconService->remove($storage, $previousPath);
            $this->markerService->forget($storage, $previousPath);
        }

        $anime->setStorage($storage);
        $anime->setStoragePath($name);
        $this->entityManager->flush();

        $this->dispatchFilesChanged($anime);

        return ManualLinkResult::linked($storage, $name, $nested);
    }

    /** Drops the link only; the snapshots downloads keep of the storage/path are left as they are. */
    public function unlink(Anime $anime): void
    {
        $anime->setStorage(null);
        $anime->setStoragePath(null);
        $this->entityManager->flush();
    }

    /** @return list<string>|null null when the root cannot be listed */
    private function listRoot(string $rootPath): ?array
    {
        $names = @scandir($rootPath);
        if ($names === false) {
            return null;
        }

        return array_values(array_filter($names, static fn (string $name): bool => $name !== '.' && $name !== '..'));
    }

    /**
     * The spelling the root listing uses: the exact match, else the single case-insensitive one.
     *
     * @param list<string> $names
     *
     * @return string|false|null false when several names match ignoring case, null when none does
     */
    private function resolveName(string $requested, array $names): string|false|null
    {
        if (\in_array($requested, $names, true)) {
            return $requested;
        }

        $matches = array_values(array_filter(
            $names,
            static fn (string $name): bool => mb_strtolower($name) === mb_strtolower($requested),
        ));

        return match (\count($matches)) {
            0 => null,
            1 => $matches[0],
            default => false,
        };
    }

    /** Another entry already bound to the pair, comparing names ignoring case and a trailing separator (v1 imports store "Foo\"). */
    private function findHolder(Storage $storage, string $name, Anime $self): ?Anime
    {
        $key = mb_strtolower($name);
        foreach ($this->animes->findByStorage($storage) as $other) {
            if ($other->id === $self->id) {
                continue;
            }

            if (mb_strtolower(rtrim((string) $other->getStoragePath(), '\\/')) === $key) {
                return $other;
            }
        }

        return null;
    }

    private function dispatchFilesChanged(Anime $anime): void
    {
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id once linked to storage.');

        try {
            $this->eventDispatcher->dispatch(new AnimeFilesChangedEvent(new AnimeId($animeId), FilesChangeReason::PathChanged));
        } catch (\Throwable $exception) {
            $this->logger->error('A plugin subscriber of AnimeFilesChangedEvent failed; the link itself is already saved.', [
                'anime_id' => $animeId,
                'exception' => $exception,
            ]);
        }
    }
}
