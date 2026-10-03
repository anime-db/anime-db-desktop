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

namespace App\Service\Download;

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Service\AppSettingsProvider;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lazily gets-or-creates the preset Storage {@see QbittorrentDownloadService::enqueue()} (the
 * plugin-contract path, which has no UI to pick a storage) puts every download into — issue #851.
 *
 * Deliberately NOT created by a migration: "%USERPROFILE%" is machine-specific, and a backup
 * could be restored onto a different profile, so the path is only ever resolved the first time
 * this process actually needs it. Identified by the id {@see AppSettingsProvider} records
 * (never by name or path — the name is user-editable and `storage.path` is not UNIQUE, so a
 * coincidental match must not be mistaken for the preset). A Storage named "Downloads" created by
 * the legacy {@see AnimeDownloadLinker} (pre-#851) is a plain Storage like any other — it is never
 * adopted as this preset just because its name matches.
 */
final class PresetDownloadsStorageProvider
{
    private const string PRESET_NAME = 'AnimeDB';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettingsProvider $settings,
        private readonly StorageMarkerService $markerService,
        private readonly DownloadStorageFilesystem $storageFilesystem,
    ) {
    }

    public function getOrCreate(): Storage
    {
        $id = $this->settings->getPresetDownloadsStorageId();
        if ($id !== null) {
            $storage = $this->entityManager->find(Storage::class, $id);
            if ($storage !== null) {
                return $storage;
            }
        }

        return $this->create();
    }

    private function create(): Storage
    {
        $path = $this->defaultPath();
        // Created before anything is persisted (not after, as it was before — see issue #851's
        // review): a clean install has no "%USERPROFILE%\Downloads\AnimeDB" yet, and a Storage
        // row surviving a failure here would leave the preset "created" with no id ever recorded
        // in settings, so every later enqueue() would retry create() and pile up duplicate rows.
        $this->storageFilesystem->ensureDirectoryExists($path);

        $storage = new Storage(self::PRESET_NAME, $path, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $this->markerService->reconcile($storage);

        $storageId = $storage->id ?? throw new \LogicException('Storage must be assigned an id right after flush().');
        $this->settings->setPresetDownloadsStorageId($storageId);

        return $storage;
    }

    /**
     * "%USERPROFILE%\Downloads\AnimeDB" — a dedicated subfolder of the user's Downloads folder,
     * not the Downloads folder itself: offering the whole Downloads folder as a Storage would
     * have the storage scanner propose every unrelated file the user ever downloaded as a new
     * catalog entry. Falls back to $HOME, then the system temp dir, on the non-Windows CI/dev
     * environment this test suite runs in.
     */
    private function defaultPath(): string
    {
        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: sys_get_temp_dir();

        return rtrim($home, '\\/').\DIRECTORY_SEPARATOR.'Downloads'.\DIRECTORY_SEPARATOR.'AnimeDB';
    }
}
