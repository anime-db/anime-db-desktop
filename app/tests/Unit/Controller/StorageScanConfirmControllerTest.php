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

namespace App\Tests\Unit\Controller;

use App\Controller\StorageScanConfirmController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Storage\FilenameCleaner;
use App\Service\Storage\OrphanAnimeMatcher;
use App\Service\Storage\ScanStorageService;
use App\Service\Storage\Search\NullSearchByPlugin;
use App\Service\Storage\Search\SearchByPluginChain;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Exercises StorageScanConfirmController against a real ScanStorageService/EntityManager
 * (same in-memory SQLite setup as ScanStorageServiceTest) rather than a mock, because
 * ScanStorageService is final and PHPUnit cannot double final classes.
 */
final class StorageScanConfirmControllerTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    private function createController(?CsrfTokenManagerInterface $csrfTokenManager = null): StorageScanConfirmController
    {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        $animeRepository = new AnimeRepository($this->entityManager);
        $scanStorageService = new ScanStorageService(
            new StorageMarkerService($this->entityManager),
            new FilenameCleaner(),
            new OrphanAnimeMatcher($animeRepository),
            new SearchByPluginChain([new NullSearchByPlugin()]),
            $animeRepository,
            $this->entityManager,
            new BulkFillerService(
                new FillerRegistry([], new PluginsConfigStore('')),
                new PluginAnimeDataMerger(
                    new StudioRepository($this->entityManager),
                    $this->entityManager,
                    $this->createStub(PluginMediaDownloaderInterface::class),
                ),
                $this->entityManager,
            ),
        );

        return new StorageScanConfirmController($scanStorageService, $this->entityManager, $csrfTokenManager);
    }

    private function jsonRequest(mixed $payload): Request
    {
        return Request::create('/storage/42/scan/confirm', 'POST', [], [], [], [], json_encode($payload) ?: '');
    }

    private function persistStorage(): Storage
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        return $storage;
    }

    public function testConfirmWithAnimeIdLinksTheExistingOrphan(): void
    {
        $storage = $this->persistStorage();

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $orphanId = $orphan->id;

        $controller = $this->createController();
        $response = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'anime_id' => $orphanId,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Trigun.mkv', $body['storage_path']);
        $this->assertSame(['id' => $orphanId, 'title' => 'Trigun'], $body['anime']);

        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $orphanId);
        $this->assertSame($storage->id, $reloaded->getStorage()?->id);
        $this->assertSame('Trigun.mkv', $reloaded->getStoragePath());
    }

    public function testConfirmWithNameCreatesANewAnimeFromThePluginCandidate(): void
    {
        $storage = $this->persistStorage();

        $controller = $this->createController();
        $response = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'name' => 'Trigun',
        ]));

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Trigun', $body['anime']['title']);
        $this->assertIsInt($body['anime']['id']);

        $this->entityManager->clear();
        /** @var Anime $reloaded */
        $reloaded = $this->entityManager->find(Anime::class, $body['anime']['id']);
        $this->assertSame('Trigun', $reloaded->getTitle());
        $this->assertSame($storage->id, $reloaded->getStorage()?->id);
        $this->assertSame('Trigun.mkv', $reloaded->getStoragePath());
    }

    public function testConfirmRejectsInvalidCsrfToken(): void
    {
        $storage = $this->persistStorage();

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);

        $this->expectException(BadRequestHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'bad',
            'storage_path' => 'Trigun.mkv',
            'anime_id' => 7,
        ]));
    }

    public function testConfirmRejectsMissingStoragePath(): void
    {
        $storage = $this->persistStorage();

        $controller = $this->createController();

        $this->expectException(BadRequestHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'anime_id' => 7,
        ]));
    }

    public function testConfirmRejectsWhenNeitherAnimeIdNorNameGiven(): void
    {
        $storage = $this->persistStorage();

        $controller = $this->createController();

        $this->expectException(BadRequestHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
        ]));
    }

    public function testConfirmRejectsASecondRequestConfirmingADifferentCandidateForTheSameStoragePath(): void
    {
        $storage = $this->persistStorage();
        $controller = $this->createController();

        // First tab confirms a plugin candidate for the file...
        $first = $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'name' => 'Trigun',
        ]));
        $this->assertSame(200, $first->getStatusCode());

        // ...a second tab, still showing the stale scan.done result, confirms a different
        // candidate for the very same storage_path (issue #147) and must be rejected, not
        // silently steal the file from the Anime the first request just created.
        $this->expectException(ConflictHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'name' => 'Trigun the Movie',
        ]));
    }

    public function testConfirmRejectsAnOrphanAlreadyLinkedToADifferentStoragePathByAnotherRequest(): void
    {
        $storage = $this->persistStorage();

        $orphan = new TvAnime();
        $orphan->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        // Simulates another request already having linked this orphan elsewhere between the
        // frontend receiving scan.done and the user clicking confirm.
        $orphan->setStorage($storage)->setStoragePath('Trigun (2026).mkv');
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();
        $orphanId = $orphan->id;

        $controller = $this->createController();

        $this->expectException(ConflictHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'anime_id' => $orphanId,
        ]));
    }

    public function testConfirmRejectsUnknownAnimeId(): void
    {
        $storage = $this->persistStorage();

        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller->confirm($storage, $this->jsonRequest([
            'token' => 'token',
            'storage_path' => 'Trigun.mkv',
            'anime_id' => 999,
        ]));
    }
}
