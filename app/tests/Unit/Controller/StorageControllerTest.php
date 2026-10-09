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
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Repository\StorageRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Storage\Scan\ScanItemResolver;
use App\Service\Storage\Scan\ScanRunJournal;
use App\Service\Storage\Scan\ScanRunStatus;
use App\Service\Storage\StorageAvailabilityService;
use App\Service\Storage\StorageMarkerService;
use App\Tests\Support\RunsMigrations;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class StorageControllerTest extends TestCase
{
    use RunsMigrations;

    /** @var list<string> */
    private array $dirsToClean = [];

    /** @var list<string> */
    private array $filesToClean = [];

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

        foreach ($this->filesToClean as $file) {
            if (is_file($file)) {
                unlink($file);
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

    /** A real AppSettingsProvider over a scratch config.json — it's final, so it can't be doubled. */
    private function createSettings(): AppSettingsProvider
    {
        $configPath = sys_get_temp_dir().'/storage-delete-test-config-'.uniqid().'.json';
        $this->filesToClean[] = $configPath;

        return new AppSettingsProvider(new AppConfigStore($configPath));
    }

    /**
     * JobLockService is final (by design — see its own docblock), so it cannot be doubled; this
     * builds a real one over an isolated in-memory "queue" connection instead, optionally with
     * $jobKey's lock already held.
     */
    private function createJournal(JobLockService $jobLockService): ScanRunJournal
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->runMigrationFile($connection, \dirname(__DIR__, 3).'/migrations/Version20261008000000.php');
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('@1000'));

        return new ScanRunJournal($connection, $jobLockService, $clock);
    }

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
        ?DownloadRepository $downloads = null,
        ?AppSettingsProvider $settings = null,
        ?ScanRunJournal $journal = null,
        ?AnimeRepository $animes = null,
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

        if ($downloads === null) {
            $downloads = $this->createStub(DownloadRepository::class);
            $downloads->method('hasUnfinishedDownloadsForTargetStorage')->willReturn(false);
        }

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
            $downloads,
            $settings ?? $this->createSettings(),
            $journal ?? $this->createJournal($jobLockService),
            new ScanItemResolver($animes ?? $this->createStub(AnimeRepository::class)),
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

        $this->assertInstanceOf(RedirectResponse::class, $response);
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

    public function testIndexPassesTheLastRunAndHowManyOfItsItemsStillNeedADecision(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 1);
        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([$storage]);

        $journal = $this->createJournal($this->createJobLockService());
        $journal->done($journal->start(1), [
            ['type' => 'NeedsManualEntry', 'storage_path' => 'Trigun'],
            ['type' => 'NeedsManualEntry', 'storage_path' => 'Monster'],
            ['type' => 'Updated', 'storage_path' => 'Bleach'],
        ]);
        $animes = $this->createStub(AnimeRepository::class);
        $animes->method('findStoragePathsByStorageId')->willReturn([5 => 'trigun\\']);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/list.html.twig', $this->callback(
                static fn (array $params): bool => isset($params['lastScans'][1])
                    && $params['lastScans'][1]['needsDecision'] === 1
                    && $params['lastScans'][1]['run']->counts == ['Updated' => 1, 'NeedsManualEntry' => 2],
            ))
            ->willReturn('<html></html>');

        $this->createController(storages: $storages, twig: $twig, journal: $journal, animes: $animes)->index();
    }

    public function testListKeepsTheCounterAndDateOfTheLastDoneRunAfterAFailedOne(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 1);
        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([$storage]);

        $journal = $this->createJournal($this->createJobLockService());
        $doneId = $journal->start(1);
        $journal->done($doneId, [['type' => 'NeedsManualEntry', 'storage_path' => 'Trigun']]);
        $failedId = $journal->start(1);
        $journal->fail($failedId, ScanRunStatus::Failed, 'disk is gone');
        $animes = $this->createStub(AnimeRepository::class);
        $animes->method('findStoragePathsByStorageId')->willReturn([]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/list.html.twig', $this->callback(
                static fn (array $params): bool => $params['lastScans'][1]['run']?->id === $doneId
                    && $params['lastScans'][1]['needsDecision'] === 1
                    && $params['lastScans'][1]['failedRun']?->id === $failedId,
            ))
            ->willReturn('<html></html>');

        $this->createController(storages: $storages, twig: $twig, journal: $journal, animes: $animes)->index();
    }

    public function testScanProgressKeepsTheLastDoneRunBesideAFailedOne(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);
        $jobLockService = $this->createJobLockService();
        $journal = $this->createJournal($jobLockService);
        $doneId = $journal->start(42);
        $journal->done($doneId, []);
        $failedId = $journal->start(42);
        $journal->fail($failedId, ScanRunStatus::Failed, 'disk is gone');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/scan_progress.html.twig', $this->callback(
                static fn (array $params): bool => $params['latestRun']?->id === $doneId && $params['failedRun']?->id === $failedId,
            ))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig, jobLockService: $jobLockService, journal: $journal)
            ->scanProgress($storage, Request::create('/storage/42/scan-progress'));
    }

    public function testScanProgressShowsTheLatestJournalRunInsteadOfNotStarted(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);
        $jobLockService = $this->createJobLockService();
        $journal = $this->createJournal($jobLockService);
        $runId = $journal->start(42);
        $journal->done($runId, []);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/scan_progress.html.twig', $this->callback(
                static fn (array $params): bool => $params['started'] === false && $params['latestRun']?->id === $runId,
            ))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig, jobLockService: $jobLockService, journal: $journal)
            ->scanProgress($storage, Request::create('/storage/42/scan-progress'));
    }

    public function testScanProgressOfALiveScanDoesNotCarryAJournalRun(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);
        $jobLockService = $this->createJobLockService();
        $journal = $this->createJournal($jobLockService);
        $journal->done($journal->start(42), []);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/scan_progress.html.twig', $this->callback(
                static fn (array $params): bool => $params['started'] === true && $params['latestRun'] === null,
            ))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig, jobLockService: $jobLockService, journal: $journal)
            ->scanProgress($storage, Request::create('/storage/42/scan-progress?started=1'));
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

        $this->assertInstanceOf(RedirectResponse::class, $response);
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

    /**
     * Acceptance (issue #853): a storage with a still-Pending (or Failed) download targeting it
     * must be refused — the row is not Completed, and downloads.target_storage_id is ON DELETE SET
     * NULL, so deleting here would strand it with no root to compare against. The marker must not
     * be touched either: forget() runs before remove()/flush() in delete(), so leaving the marker
     * file in place proves forget() itself was skipped, not just that it happened to no-op.
     */
    public function testDeleteRejectsStorageWithUnfinishedDownloads(): void
    {
        $dir = $this->makeDir();
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'desktop.ini', "[AnimeDB]\nid=42\n");
        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->setStorageId($storage, 42);

        $downloads = $this->createMock(DownloadRepository::class);
        $downloads->expects($this->once())
            ->method('hasUnfinishedDownloadsForTargetStorage')
            ->with(42)
            ->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([$storage]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/list.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_list.delete_error_unfinished_downloads'
                    && $params['errorParams'] === ['%name%' => 'Main folder'],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(storages: $storages, entityManager: $entityManager, twig: $twig, downloads: $downloads);
        $request = Request::create('/storage/42/delete', 'POST', ['_token' => 'token']);

        $response = $controller->delete($storage, $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFileExists($dir.\DIRECTORY_SEPARATOR.'desktop.ini');
    }

    /**
     * Acceptance (issue #853): the preset downloads storage can never be deleted, regardless of
     * whether it currently has any downloads at all — the DownloadRepository stub here always
     * reports no unfinished downloads, so the refusal can only be coming from the preset check.
     */
    public function testDeletePresetStorageIsRejectedEvenWithoutDownloads(): void
    {
        $storage = new Storage('AnimeDB', 'D:\\Downloads\\AnimeDB', StorageType::Folder);
        $this->setStorageId($storage, 7);

        $settings = $this->createSettings();
        $settings->setPresetDownloadsStorageId(7);

        $downloads = $this->createStub(DownloadRepository::class);
        $downloads->method('hasUnfinishedDownloadsForTargetStorage')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $entityManager->expects($this->never())->method('flush');

        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([$storage]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/list.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_list.delete_error_preset',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(
            storages: $storages,
            entityManager: $entityManager,
            twig: $twig,
            downloads: $downloads,
            settings: $settings,
        );
        $request = Request::create('/storage/7/delete', 'POST', ['_token' => 'token']);

        $response = $controller->delete($storage, $request);

        $this->assertSame(200, $response->getStatusCode());
    }

    private function setStorageId(Storage $storage, int $id): void
    {
        $property = new \ReflectionProperty(Storage::class, 'id');
        $property->setValue($storage, $id);
    }
}
