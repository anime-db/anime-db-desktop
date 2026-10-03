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

namespace App\Tests\Unit\Controller;

use App\Controller\StorageController;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Message\ScanStorageMessage;
use App\Repository\StorageRepository;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Storage\StorageAvailabilityService;
use App\Service\Storage\StorageMarkerService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class StorageControllerTest extends TestCase
{
    /** @var list<string> */
    private array $dirsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->dirsToClean as $dir) {
            $marker = $dir.\DIRECTORY_SEPARATOR.'desktop.ini';
            if (is_file($marker)) {
                unlink($marker);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    private function makeDir(): string
    {
        $dir = sys_get_temp_dir().'/storage-delete-test-'.uniqid();
        mkdir($dir, recursive: true);
        $this->dirsToClean[] = $dir;

        return $dir;
    }

    /**
     * JobLockService is final (by design — see its own docblock), so it cannot be doubled; this
     * builds a real one over an isolated in-memory "queue" connection instead, optionally with
     * $jobKey's lock already held.
     */
    private function createJobLockService(?string $heldJobKey = null): JobLockService
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('@1000'));

        $jobLockService = new JobLockService($connection, $this->createStub(ProcessLivenessChecker::class), $clock);

        if ($heldJobKey !== null) {
            $jobLockService->acquire($heldJobKey);
        }

        return $jobLockService;
    }

    private function createController(
        ?StorageRepository $storages = null,
        ?MessageBusInterface $messageBus = null,
        ?EntityManagerInterface $entityManager = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?Environment $twig = null,
        ?StorageMarkerService $storageMarker = null,
        ?StorageAvailabilityService $storageAvailability = null,
        ?JobLockService $jobLockService = null,
    ): StorageController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/storage?scanned=1');
        }

        if ($messageBus === null) {
            $messageBus = $this->createStub(MessageBusInterface::class);
            $messageBus->method('dispatch')->willReturnCallback(
                static fn (object $message): Envelope => new Envelope($message),
            );
        }

        if ($storageMarker === null) {
            $markerEntityManager = $this->createStub(EntityManagerInterface::class);
            $markerEntityManager->method('find')->willReturn(null);
            $storageMarker = new StorageMarkerService($markerEntityManager);
        }

        $jobLockService ??= $this->createJobLockService();

        return new StorageController(
            $storages ?? $this->createStub(StorageRepository::class),
            $messageBus,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $csrfTokenManager,
            $urlGenerator,
            $twig ?? $this->createStub(Environment::class),
            $storageMarker,
            $storageAvailability ?? new StorageAvailabilityService(),
            $jobLockService,
        );
    }

    public function testIndexPassesStoragesToTemplate(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 1);

        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([$storage]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/list.html.twig', $this->callback(
                static fn (array $params): bool => [$storage] === $params['storages'],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(storages: $storages, twig: $twig);
        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexMarksStorageWithMissingPathAsUnavailable(): void
    {
        $missingPath = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'storage-missing-'.uniqid();
        $storage = new Storage('Main folder', $missingPath, StorageType::Folder);
        $this->setStorageId($storage, 7);

        $this->assertFalse(is_readable($missingPath));

        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([$storage]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/list.html.twig', $this->callback(
                static fn (array $params): bool => $params['unavailableStorageIds'] === [7],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(storages: $storages, twig: $twig);
        $controller->index();
    }

    public function testIndexDoesNotMarkStorageWithExistingPathAsUnavailable(): void
    {
        $dir = $this->makeDir();
        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->setStorageId($storage, 3);

        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([$storage]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/list.html.twig', $this->callback(
                static fn (array $params): bool => $params['unavailableStorageIds'] === [],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(storages: $storages, twig: $twig);
        $controller->index();
    }

    public function testDeleteRemovesStorageWithMissingPath(): void
    {
        $missingPath = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'storage-missing-'.uniqid();
        $storage = new Storage('Main folder', $missingPath, StorageType::Folder);
        $this->setStorageId($storage, 11);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($storage);
        $entityManager->expects($this->once())->method('flush');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('storage_index')
            ->willReturn('/storage');

        $controller = $this->createController(entityManager: $entityManager, urlGenerator: $router);
        $request = Request::create('/storage/11/delete', 'POST', ['_token' => 'token']);

        $response = $controller->delete($storage, $request);

        $this->assertSame('/storage', $response->getTargetUrl());
    }

    public function testPathsReturnsAllConfiguredStoragePaths(): void
    {
        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([
            new Storage('Main folder', 'D:\\Anime', StorageType::Folder),
            new Storage('Backup folder', 'E:\\Anime backup', StorageType::Folder),
        ]);

        $controller = $this->createController(storages: $storages);
        $response = $controller->paths();

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame(['D:\\Anime', 'E:\\Anime backup'], $data['paths']);
    }

    public function testScanDispatchesScanStorageMessageAndRedirects(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                static fn (object $message): bool => $message instanceof ScanStorageMessage && $message->storageId === 42,
            ))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('storage_scan_progress', ['id' => 42, 'started' => 1])
            ->willReturn('/storage/42/scan-progress?started=1');

        $controller = $this->createController(messageBus: $messageBus, urlGenerator: $router);
        $request = Request::create('/storage/42/scan', 'POST', ['_token' => 'token']);

        $response = $controller->scan($storage, $request);

        $this->assertSame('/storage/42/scan-progress?started=1', $response->getTargetUrl());
    }

    public function testScanProgressRendersTheLiveScanSectionWhenAScanLockIsHeld(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/scan_progress.html.twig', $this->callback(
                static fn (array $params): bool => $params['storage'] === $storage && $params['started'] === true,
            ))
            ->willReturn('<html></html>');

        $jobLockService = $this->createJobLockService(heldJobKey: ScanStorageMessage::jobKey(42));

        $controller = $this->createController(twig: $twig, jobLockService: $jobLockService);

        $response = $controller->scanProgress($storage, Request::create('/storage/42/scan-progress'));

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Regression (issue #834 review, round 2): a request landing right after scan() redirects
     * here — before the async consumer picks the message up and takes the job lock — must still
     * show the live-scan section. The one-shot `?started=1` marker scan() puts on that redirect
     * covers exactly that gap; storage-scan.js strips it from the URL once it mounts, so it is
     * never present on a later F5/back-forward/bookmarked visit.
     */
    public function testScanProgressRendersTheLiveScanSectionWhenStartedQueryParamIsPresentEvenWithoutLock(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/scan_progress.html.twig', $this->callback(
                static fn (array $params): bool => $params['started'] === true,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig, jobLockService: $this->createJobLockService());

        $controller->scanProgress($storage, Request::create('/storage/42/scan-progress?started=1'));
    }

    /**
     * Regression (issue #834 review): the template's live-scan-section state must come from
     * whether a scan is actually running (the job lock) or was just triggered (`?started=1`), not
     * stay true forever once that query string shows up once — an F5/back-forward/bookmarked
     * visit without the parameter and without the lock held must fall back to "scan not started"
     * rather than showing the live section after the scan already finished (storage-scan.js's
     * 15-second no-response timeout, then a dead-end error).
     */
    public function testScanProgressRendersTheNotStartedStateWhenNoScanLockIsHeldAndNoStartedQueryParam(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/scan_progress.html.twig', $this->callback(
                static fn (array $params): bool => $params['started'] === false,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig, jobLockService: $this->createJobLockService());

        $controller->scanProgress($storage, Request::create('/storage/42/scan-progress'));
    }

    public function testScanRejectsInvalidCsrfToken(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $controller = $this->createController(messageBus: $messageBus, csrfTokenManager: $csrf);
        $request = Request::create('/storage/42/scan', 'POST', ['_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->scan($storage, $request);
    }

    public function testDeleteRemovesStorageAndRedirects(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($storage);
        $entityManager->expects($this->once())->method('flush');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('storage_index')
            ->willReturn('/storage');

        $controller = $this->createController(entityManager: $entityManager, urlGenerator: $router);
        $request = Request::create('/storage/42/delete', 'POST', ['_token' => 'token']);

        $response = $controller->delete($storage, $request);

        $this->assertSame('/storage', $response->getTargetUrl());
    }

    public function testDeleteRemovesMarkerFile(): void
    {
        $dir = $this->makeDir();
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'desktop.ini', "[AnimeDB]\nid=42\n");
        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->setStorageId($storage, 42);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove')->with($storage);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController(entityManager: $entityManager);
        $request = Request::create('/storage/42/delete', 'POST', ['_token' => 'token']);

        $controller->delete($storage, $request);

        $this->assertFileDoesNotExist($dir.\DIRECTORY_SEPARATOR.'desktop.ini');
    }

    public function testDeleteRejectsInvalidCsrfToken(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');

        $controller = $this->createController(entityManager: $entityManager, csrfTokenManager: $csrf);
        $request = Request::create('/storage/42/delete', 'POST', ['_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->delete($storage, $request);
    }

    private function setStorageId(Storage $storage, int $id): void
    {
        $property = new \ReflectionProperty(Storage::class, 'id');
        $property->setValue($storage, $id);
    }
}
