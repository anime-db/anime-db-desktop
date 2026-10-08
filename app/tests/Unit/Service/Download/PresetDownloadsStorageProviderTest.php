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

namespace App\Tests\Unit\Service\Download;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Download\NativeDownloadStorageFilesystem;
use App\Service\Download\PresetDownloadsStorageProvider;
use App\Service\Exception\DownloadStorageUnavailableException;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class PresetDownloadsStorageProviderTest extends TestCase
{
    private EntityManager $entityManager;
    private AppSettingsProvider $settings;
    private StorageMarkerService $markerService;
    private string $configPath;
    private string $homeDir;
    private string|false $previousHome;
    private string|false $previousUserprofile;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->configPath = sys_get_temp_dir().'/anime-preset-storage-test-'.uniqid().'.json';
        $this->settings = new AppSettingsProvider(new AppConfigStore($this->configPath));
        $this->markerService = new StorageMarkerService($this->entityManager);

        // Deliberately NOT pre-created: a clean install has no "Downloads/AnimeDB" yet, and
        // getOrCreate() must bring it into existence itself (issue #851's review) rather than
        // assume a directory a prior test's setUp() happened to leave behind.
        $this->homeDir = sys_get_temp_dir().'/anime-preset-storage-home-'.uniqid();

        $this->previousHome = getenv('HOME');
        $this->previousUserprofile = getenv('USERPROFILE');
        putenv('HOME='.$this->homeDir);
        putenv('USERPROFILE='.$this->homeDir);
    }

    protected function tearDown(): void
    {
        putenv($this->previousUserprofile === false ? 'USERPROFILE' : 'USERPROFILE='.$this->previousUserprofile);
        putenv($this->previousHome === false ? 'HOME' : 'HOME='.$this->previousHome);

        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->removeRecursively($this->homeDir);
    }

    private function removeRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeRecursively($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function provider(): PresetDownloadsStorageProvider
    {
        return new PresetDownloadsStorageProvider($this->entityManager, $this->settings, $this->markerService, new NativeDownloadStorageFilesystem());
    }

    public function testGetOrCreateCreatesAFolderStorageUnderDownloadsAndRecordsItsIdInSettings(): void
    {
        $storage = $this->provider()->getOrCreate();

        $this->assertSame(StorageType::Folder, $storage->getType());
        $this->assertSame($this->homeDir.\DIRECTORY_SEPARATOR.'Downloads'.\DIRECTORY_SEPARATOR.'AnimeDB', $storage->getPath());
        $this->assertSame($storage->id, $this->settings->getPresetDownloadsStorageId());
    }

    public function testGetOrCreateWritesAMarkerForTheNewPreset(): void
    {
        $storage = $this->provider()->getOrCreate();

        $this->assertDirectoryExists($storage->requirePath());
        $this->assertSame($storage->id, $this->markerService->readMarkerId($storage->requirePath()));
    }

    public function testGetOrCreateReturnsTheSameStorageOnASecondCall(): void
    {
        $provider = $this->provider();
        $first = $provider->getOrCreate();
        $second = $provider->getOrCreate();

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, $this->entityManager->getRepository(Storage::class)->findAll());
    }

    public function testGetOrCreateReturnsTheSameStorageFromAFreshProviderInstance(): void
    {
        $firstId = $this->provider()->getOrCreate()->id;
        $secondId = $this->provider()->getOrCreate()->id;

        $this->assertSame($firstId, $secondId);
        $this->assertCount(1, $this->entityManager->getRepository(Storage::class)->findAll());
    }

    public function testGetOrCreateRecreatesThePresetWhenTheConfiguredIdPointsAtNoExistingStorage(): void
    {
        $this->settings->setPresetDownloadsStorageId(999999);

        $storage = $this->provider()->getOrCreate();

        $this->assertNotSame(999999, $storage->id);
        $this->assertSame($storage->id, $this->settings->getPresetDownloadsStorageId());
    }

    /**
     * A Storage with the same path and name 'Downloads' as the legacy AnimeDownloadLinker's own
     * find-or-create storage (pre-#851), but a DIFFERENT id than the one in settings, must not be
     * mistaken for the preset — identification is strictly by id (issue #851).
     */
    public function testGetOrCreateIgnoresAStorageWithTheSamePathOrNameButADifferentId(): void
    {
        $unrelated = new Storage('Downloads', $this->homeDir.\DIRECTORY_SEPARATOR.'Downloads'.\DIRECTORY_SEPARATOR.'AnimeDB', StorageType::Folder);
        $this->entityManager->persist($unrelated);
        $this->entityManager->flush();

        $this->settings->setPresetDownloadsStorageId($unrelated->id + 1);

        $storage = $this->provider()->getOrCreate();

        $this->assertNotSame($unrelated->id, $storage->id);
    }

    /**
     * A human may have set up a Storage on "Downloads/AnimeDB" before this preset ever ran there
     * (or picked it as a relocation target) — its marker already names that *other*, still
     * existing Storage. create() must not adopt the path anyway: it must roll back the row it
     * just persisted and never record an id in settings, or every later enqueue() would reject
     * downloads through assertStorageAvailable() forever with no way for getOrCreate() to retry
     * (issue #851's review).
     */
    public function testGetOrCreateRollsBackAndThrowsWhenTheMarkerAtThePresetPathNamesAnotherExistingStorage(): void
    {
        $other = new Storage('Other', $this->homeDir.\DIRECTORY_SEPARATOR.'other', StorageType::Folder);
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $presetPath = $this->homeDir.\DIRECTORY_SEPARATOR.'Downloads'.\DIRECTORY_SEPARATOR.'AnimeDB';
        mkdir($presetPath, recursive: true);
        file_put_contents($presetPath.\DIRECTORY_SEPARATOR.'desktop.ini', "[AnimeDB]\nid={$other->id}\n");

        try {
            $this->provider()->getOrCreate();
            $this->fail('Expected a DownloadStorageUnavailableException.');
        } catch (DownloadStorageUnavailableException $e) {
            $this->assertSame($presetPath, $e->path);
        }

        $this->assertNull($this->settings->getPresetDownloadsStorageId());
        $this->assertCount(1, $this->entityManager->getRepository(Storage::class)->findAll());
    }

    /**
     * Any failure between the preset row's flush() and its id landing in settings — not only the
     * Conflict case above — must roll back the same way: the row persisted so far is removed,
     * nothing is written to settings, and the exception still propagates. A broken
     * StorageMarkerService (its EntityManager pointed at a connection with no "storage" table)
     * stands in for any real failure reconcile() could hit (lost DB connection, locked file).
     */
    public function testGetOrCreateRollsBackWhenReconcileThrows(): void
    {
        $brokenConfig = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $brokenConfig->enableNativeLazyObjects(true);
        $brokenConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $brokenConfig);
        $brokenEntityManager = new EntityManager($brokenConnection, $brokenConfig);
        $brokenMarkerService = new StorageMarkerService($brokenEntityManager);

        $presetPath = $this->homeDir.\DIRECTORY_SEPARATOR.'Downloads'.\DIRECTORY_SEPARATOR.'AnimeDB';
        mkdir($presetPath, recursive: true);
        file_put_contents($presetPath.\DIRECTORY_SEPARATOR.'desktop.ini', "[AnimeDB]\nid=999999\n");

        $provider = new PresetDownloadsStorageProvider(
            $this->entityManager,
            $this->settings,
            $brokenMarkerService,
            new NativeDownloadStorageFilesystem(),
        );

        try {
            $provider->getOrCreate();
            $this->fail('Expected an exception from reconcile().');
        } catch (\Throwable) {
            // Expected — the broken marker service's connection has no "storage" table.
        }

        $this->assertNull($this->settings->getPresetDownloadsStorageId());
        $this->assertCount(0, $this->entityManager->getRepository(Storage::class)->findAll());
    }
}
