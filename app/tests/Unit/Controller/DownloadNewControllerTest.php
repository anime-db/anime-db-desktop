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

use AnimeDb\PluginContracts\Download\DownloadAlreadyLinkedToAnotherAnimeException;
use AnimeDb\PluginContracts\Download\DownloadSource;
use AnimeDb\PluginContracts\Download\DownloadTaskId;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Controller\DownloadNewController;
use App\Entity\Anime;
use App\Entity\Download;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Repository\StorageRepository;
use App\Service\AppSettingsProvider;
use App\Service\Download\PresetDownloadsStorageProvider;
use App\Service\Download\QbittorrentDownloadService;
use App\Service\Exception\DownloadAlreadyInClientException;
use App\Service\Exception\DownloadStorageUnavailableException;
use App\Service\Exception\InsufficientDiskSpaceException;
use App\Service\Exception\InvalidTorrentFileException;
use App\Service\Exception\QbittorrentClientException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DownloadNewControllerTest extends TestCase
{
    private const string SOME_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var list<string> */
    private array $filesToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->filesToClean as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function makeAnime(int $id): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Test anime')->setWatchStatus(WatchStatus::Plan);
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime, $id);

        return $anime;
    }

    private function makeStorage(int $id, StorageType $type = StorageType::Folder): Storage
    {
        $storage = new Storage('Storage '.$id, sys_get_temp_dir(), $type);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);

        return $storage;
    }

    private function makeUploadedFile(string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/download-new-test-upload-'.uniqid();
        file_put_contents($path, $content);
        $this->filesToClean[] = $path;

        return new UploadedFile($path, 'release.torrent', 'application/x-bittorrent', null, true);
    }

    /**
     * A validly-received upload whose move() still fails (e.g. the temp directory is
     * unwritable) — a real-world FileException that {@see UploadedFile::move()} itself can
     * throw. There is no public way to make Symfony's own move() fail on demand, so this
     * overrides it directly.
     */
    private function makeUploadedFileThatFailsToMove(string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/download-new-test-upload-'.uniqid();
        file_put_contents($path, $content);
        $this->filesToClean[] = $path;

        return new class($path, 'release.torrent', 'application/x-bittorrent', null, true) extends UploadedFile {
            public function move(string $directory, ?string $name = null): File
            {
                throw new FileException('Could not move the uploaded file.');
            }
        };
    }

    private function createController(
        ?AnimeRepository $animeRepository = null,
        ?StorageRepository $storageRepository = null,
        ?DownloadRepository $downloadRepository = null,
        ?PresetDownloadsStorageProvider $presetStorageProvider = null,
        ?QbittorrentDownloadService $downloadService = null,
        ?AppSettingsProvider $settings = null,
        ?EntityManagerInterface $entityManager = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?TranslatorInterface $translator = null,
        ?Environment $twig = null,
        ?LoggerInterface $logger = null,
    ): DownloadNewController {
        if ($animeRepository === null) {
            $animeRepository = $this->createStub(AnimeRepository::class);
            $animeRepository->method('findByIds')->willReturn([]);
        }

        if ($storageRepository === null) {
            $storageRepository = $this->createStub(StorageRepository::class);
            $storageRepository->method('findAllScannable')->willReturn([]);
        }

        if ($downloadRepository === null) {
            $downloadRepository = $this->createStub(DownloadRepository::class);
            $downloadRepository->method('findByAnime')->willReturn([]);
        }

        if ($presetStorageProvider === null) {
            $presetStorageProvider = $this->createStub(PresetDownloadsStorageProvider::class);
            $presetStorageProvider->method('getOrCreate')->willReturn($this->makeStorage(1));
        }

        if ($downloadService === null) {
            $downloadService = $this->createStub(QbittorrentDownloadService::class);
            $downloadService->method('enqueueTo')->willReturn(new DownloadTaskId(self::SOME_HASH));
        }

        if ($settings === null) {
            $settings = $this->createStub(AppSettingsProvider::class);
            $settings->method('getLastDownloadStorageId')->willReturn(null);
        }

        if ($entityManager === null) {
            $entityManager = $this->createStub(EntityManagerInterface::class);
            $entityManager->method('find')->willReturn($this->makeStorage(1));
        }

        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/downloads');
        }

        if ($translator === null) {
            $translator = $this->createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnArgument(0);
            $translator->method('getLocale')->willReturn('en');
        }

        if ($twig === null) {
            $twig = $this->createStub(Environment::class);
            $twig->method('render')->willReturn('<html></html>');
        }

        return new DownloadNewController(
            $animeRepository,
            $storageRepository,
            $downloadRepository,
            $presetStorageProvider,
            $downloadService,
            $settings,
            $entityManager,
            $csrfTokenManager,
            $urlGenerator,
            $translator,
            $twig,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    /**
     * @param array<string, string> $overrides
     */
    private function magnetRequest(array $overrides = []): Request
    {
        return Request::create('/downloads/new', 'POST', array_merge([
            '_token' => 'token',
            'magnet' => 'magnet:?xt=urn:btih:'.self::SOME_HASH,
            'anime' => '5',
            'storage' => '1',
        ], $overrides));
    }

    public function testNewPrefillsSelectedAnimeFromQueryParam(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['selectedAnime'] === $anime,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, twig: $twig);
        $response = $controller->new(Request::create('/downloads/new?anime=5'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateRejectsOversizedContentLengthBeforeCsrfCheck(): void
    {
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->expects($this->never())->method('isTokenValid');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_file_too_large',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(csrfTokenManager: $csrf, twig: $twig);

        // Mirrors what PHP itself does once a request body exceeds post_max_size: $_POST/$_FILES
        // are empty, but CONTENT_LENGTH is still set from the request line.
        $request = Request::create('/downloads/new', 'POST', [], [], [], [
            'CONTENT_LENGTH' => (string) (17 * 1024 * 1024),
        ]);

        $response = $controller->create($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $downloadService = $this->createMock(QbittorrentDownloadService::class);
        $downloadService->expects($this->never())->method('enqueueTo');

        $controller = $this->createController(csrfTokenManager: $csrf, downloadService: $downloadService);

        $this->expectException(BadRequestHttpException::class);
        $controller->create($this->magnetRequest());
    }

    public function testCreateWithMagnetEnqueuesAndRedirectsAndUpdatesLastDownloadStorageId(): void
    {
        $anime = $this->makeAnime(5);
        // Deliberately distinct from the preset storage's id (1, see createController()'s default
        // stub) so a wrong implementation that saves the preset/default id instead of the
        // actually-selected one would be caught.
        $storage = $this->makeStorage(7);

        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('find')->with(Storage::class, 7)->willReturn($storage);

        $downloadService = $this->createMock(QbittorrentDownloadService::class);
        $downloadService->expects($this->once())
            ->method('enqueueTo')
            ->with(
                $this->callback(static fn (DownloadSource $source): bool => $source->value === 'magnet:?xt=urn:btih:'.self::SOME_HASH),
                $this->callback(static fn (AnimeId $id): bool => $id->value === 5),
                $storage,
            )
            ->willReturn(new DownloadTaskId(self::SOME_HASH));

        $settings = $this->createMock(AppSettingsProvider::class);
        $settings->method('getLastDownloadStorageId')->willReturn(null);
        $settings->expects($this->once())->method('setLastDownloadStorageId')->with(7);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())->method('generate')->with('downloads_index')->willReturn('/downloads');

        $controller = $this->createController(
            animeRepository: $animeRepository,
            entityManager: $entityManager,
            downloadService: $downloadService,
            settings: $settings,
            urlGenerator: $urlGenerator,
        );

        $response = $controller->create($this->magnetRequest(['storage' => '7']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/downloads', $response->getTargetUrl());
    }

    public function testCreateWithTorrentFileMovesUploadToATorrentPathAndDeletesItOnSuccess(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $capturedPath = null;
        $downloadService = $this->createMock(QbittorrentDownloadService::class);
        $downloadService->expects($this->once())
            ->method('enqueueTo')
            ->with($this->callback(function (DownloadSource $source) use (&$capturedPath): bool {
                $capturedPath = $source->value;

                return str_ends_with($source->value, '.torrent') && is_file($source->value);
            }))
            ->willReturn(new DownloadTaskId(self::SOME_HASH));

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService);

        $request = $this->magnetRequest(['magnet' => '']);
        $request->files->set('torrent_file', $this->makeUploadedFile('d8:announce0:4:infod0:ee'));

        $controller->create($request);

        $this->assertNotNull($capturedPath);
        $this->assertFileDoesNotExist($capturedPath);
    }

    public function testCreateRejectsAnUploadThatExceedsTheIniUploadLimitAsFileTooLarge(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $downloadService = $this->createMock(QbittorrentDownloadService::class);
        $downloadService->expects($this->never())->method('enqueueTo');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_file_too_large',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService, twig: $twig);

        $request = $this->magnetRequest(['magnet' => '']);
        // test=true + a non-OK error lets File skip its "path must exist" check (see
        // UploadedFile::__construct()), so this models what PHP itself hands over once
        // upload_max_filesize is exceeded without needing an actual oversized upload.
        $request->files->set('torrent_file', new UploadedFile(
            '/nonexistent/release.torrent',
            'release.torrent',
            'application/x-bittorrent',
            \UPLOAD_ERR_INI_SIZE,
            true,
        ));

        $controller->create($request);
    }

    public function testCreateRejectsAnOtherwiseInvalidUploadAsUnreadableSource(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $downloadService = $this->createMock(QbittorrentDownloadService::class);
        $downloadService->expects($this->never())->method('enqueueTo');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_unreadable_source',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService, twig: $twig);

        $request = $this->magnetRequest(['magnet' => '']);
        $request->files->set('torrent_file', new UploadedFile(
            '/nonexistent/release.torrent',
            'release.torrent',
            'application/x-bittorrent',
            \UPLOAD_ERR_PARTIAL,
            true,
        ));

        $controller->create($request);
    }

    public function testCreateTreatsAFailedMoveOfAValidUploadAsUnreadableSource(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $downloadService = $this->createMock(QbittorrentDownloadService::class);
        $downloadService->expects($this->never())->method('enqueueTo');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_unreadable_source',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService, twig: $twig);

        $request = $this->magnetRequest(['magnet' => '']);
        $request->files->set('torrent_file', $this->makeUploadedFileThatFailsToMove('d8:announce0:4:infod0:ee'));

        $response = $controller->create($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateDeletesTempTorrentFileWhenEnqueueThrows(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $capturedPath = null;
        $downloadService = $this->createStub(QbittorrentDownloadService::class);
        $downloadService->method('enqueueTo')
            ->willReturnCallback(function (DownloadSource $source) use (&$capturedPath): never {
                $capturedPath = $source->value;

                throw new InvalidTorrentFileException('broken');
            });

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService);

        $request = $this->magnetRequest(['magnet' => '']);
        $request->files->set('torrent_file', $this->makeUploadedFile('not a torrent'));

        $response = $controller->create($request);

        $this->assertNotNull($capturedPath);
        $this->assertFileDoesNotExist($capturedPath);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateDoesNotChangeLastDownloadStorageIdWhenEnqueueFails(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $downloadService = $this->createStub(QbittorrentDownloadService::class);
        $downloadService->method('enqueueTo')->willThrowException(new QbittorrentClientException('down'));

        $settings = $this->createMock(AppSettingsProvider::class);
        $settings->method('getLastDownloadStorageId')->willReturn(null);
        $settings->expects($this->never())->method('setLastDownloadStorageId');

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService, settings: $settings);

        $controller->create($this->magnetRequest());
    }

    public function testCreateWithoutAnimeRendersErrorAndDoesNotEnqueue(): void
    {
        $downloadService = $this->createMock(QbittorrentDownloadService::class);
        $downloadService->expects($this->never())->method('enqueueTo');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_anime_required',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(downloadService: $downloadService, twig: $twig);

        $response = $controller->create($this->magnetRequest(['anime' => '0']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateWithoutStorageRendersError(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_storage_required',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, entityManager: $entityManager, twig: $twig);

        $controller->create($this->magnetRequest(['storage' => '999']));
    }

    public function testCreateWithoutSourceRendersError(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_source_required',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, twig: $twig);

        $controller->create($this->magnetRequest(['magnet' => '']));
    }

    public function testCreateRendersUnreadableSourceErrorForAnInvalidMagnet(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $downloadService = $this->createMock(QbittorrentDownloadService::class);
        $downloadService->expects($this->never())->method('enqueueTo');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_unreadable_source',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService, twig: $twig);

        $controller->create($this->magnetRequest(['magnet' => 'not-a-magnet-link']));
    }

    public function testCreateMapsInsufficientDiskSpaceExceptionToAFormattedMessage(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $downloadService = $this->createStub(QbittorrentDownloadService::class);
        $downloadService->method('enqueueTo')->willThrowException(
            new InsufficientDiskSpaceException(2_000_000_000, 500_000_000, '/mnt/storage'),
        );

        // The default controller-test translator stub (willReturnArgument(0)) just echoes the
        // unit key back, so it can't tell "%needed%" and "%free%" apart — both would stringify
        // to the same key regardless of value. Simulate real translation so the two formatted
        // byte counts are distinguishable and this test actually exercises formatBytes().
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            $unit = match ($id) {
                'downloads.unit_b' => 'B',
                'downloads.unit_kb' => 'KB',
                'downloads.unit_mb' => 'MB',
                'downloads.unit_gb' => 'GB',
                'downloads.unit_tb' => 'TB',
                default => $id,
            };

            return isset($params['%value%']) ? $params['%value%'].' '.$unit : $id;
        });
        $translator->method('getLocale')->willReturn('en');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_insufficient_space'
                    && $params['errorParams']['%needed%'] === '1.9 GB'
                    && $params['errorParams']['%free%'] === '476.8 MB'
                    && $params['errorParams']['%path%'] === '/mnt/storage',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(
            animeRepository: $animeRepository,
            downloadService: $downloadService,
            translator: $translator,
            twig: $twig,
        );

        $controller->create($this->magnetRequest());
    }

    public function testCreateMapsAlreadyLinkedExceptionWithOccupyingAnimeId(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $downloadService = $this->createStub(QbittorrentDownloadService::class);
        $downloadService->method('enqueueTo')->willThrowException(
            new DownloadAlreadyLinkedToAnotherAnimeException(self::SOME_HASH, new AnimeId(42)),
        );

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_already_linked'
                    && $params['occupyingAnimeId'] === 42,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService, twig: $twig);

        $controller->create($this->magnetRequest());
    }

    public function testCreateMapsAlreadyInClientExceptionToADownloadsPageHintKeepingTheForm(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $downloadService = $this->createStub(QbittorrentDownloadService::class);
        $downloadService->method('enqueueTo')->willThrowException(new DownloadAlreadyInClientException(self::SOME_HASH));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_already_in_client'
                    && $params['downloadsLink'] === true
                    && $params['adoptInfoHash'] === self::SOME_HASH
                    && $params['selectedAnime'] === $anime
                    && $params['selectedStorageId'] === 1
                    && $params['magnet'] !== '',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService, twig: $twig);

        $response = $controller->create($this->magnetRequest());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateMapsStorageUnavailableException(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $downloadService = $this->createStub(QbittorrentDownloadService::class);
        $downloadService->method('enqueueTo')->willThrowException(new DownloadStorageUnavailableException(1, '/mnt/storage'));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_storage_unavailable',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService, twig: $twig);

        $controller->create($this->magnetRequest());
    }

    public function testCreateMapsQbittorrentClientExceptionToClientUnavailableMessage(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $downloadService = $this->createStub(QbittorrentDownloadService::class);
        $downloadService->method('enqueueTo')->willThrowException(new QbittorrentClientException('unreachable'));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'download_new.error_client_unavailable',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(animeRepository: $animeRepository, downloadService: $downloadService, twig: $twig);

        $controller->create($this->magnetRequest());
    }

    public function testCreateShowsAlreadyInDownloadsMessageForAnIdempotentRepeatButStillUpdatesSetting(): void
    {
        $anime = $this->makeAnime(5);
        $storage = $this->makeStorage(7);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('find')->with(Storage::class, 7)->willReturn($storage);

        $existing = new Download(self::SOME_HASH, $anime);

        // The pre-enqueue snapshot of existing downloads must be taken BEFORE enqueueTo() runs:
        // enqueueTo() itself persists a Download for a brand-new task, so a snapshot taken
        // afterwards would always already contain the just-enqueued hash and every new download
        // would be misreported as "already in downloads". $callOrder pins down that ordering
        // instead of relying on a stub that returns the same answer regardless of call order.
        $callOrder = [];

        $downloadRepository = $this->createMock(DownloadRepository::class);
        $downloadRepository->expects($this->once())
            ->method('findByAnime')
            ->with(5)
            ->willReturnCallback(function () use (&$callOrder, $existing): array {
                $callOrder[] = 'findByAnime';

                return [$existing];
            });

        $downloadService = $this->createMock(QbittorrentDownloadService::class);
        $downloadService->expects($this->once())
            ->method('enqueueTo')
            ->willReturnCallback(function () use (&$callOrder): DownloadTaskId {
                $callOrder[] = 'enqueueTo';

                return new DownloadTaskId(self::SOME_HASH);
            });

        $settings = $this->createMock(AppSettingsProvider::class);
        $settings->method('getLastDownloadStorageId')->willReturn(null);
        $settings->expects($this->once())->method('setLastDownloadStorageId')->with(7);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['info'] === 'download_new.info_already_in_downloads',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(
            animeRepository: $animeRepository,
            entityManager: $entityManager,
            downloadRepository: $downloadRepository,
            downloadService: $downloadService,
            settings: $settings,
            twig: $twig,
        );

        $response = $controller->create($this->magnetRequest(['storage' => '7']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['findByAnime', 'enqueueTo'], $callOrder);
    }

    public function testCreateRedirectsWhenAnimeHasAnUnrelatedDownloadThatDoesNotMatchTheNewTaskId(): void
    {
        $anime = $this->makeAnime(5);
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $anime]);

        $unrelatedHash = str_repeat('b', 40);
        $unrelated = new Download($unrelatedHash, $anime);
        $downloadRepository = $this->createStub(DownloadRepository::class);
        $downloadRepository->method('findByAnime')->willReturn([$unrelated]);

        $downloadService = $this->createStub(QbittorrentDownloadService::class);
        $downloadService->method('enqueueTo')->willReturn(new DownloadTaskId(self::SOME_HASH));

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())->method('generate')->with('downloads_index')->willReturn('/downloads');

        $controller = $this->createController(
            animeRepository: $animeRepository,
            downloadRepository: $downloadRepository,
            downloadService: $downloadService,
            urlGenerator: $urlGenerator,
        );

        $response = $controller->create($this->magnetRequest());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/downloads', $response->getTargetUrl());
    }

    public function testStorageOptionsDefaultToLastDownloadStorageIdWhenValid(): void
    {
        $preset = $this->makeStorage(1);
        $writable = $this->makeStorage(2);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findAllScannable')->willReturn([$writable]);

        $presetProvider = $this->createStub(PresetDownloadsStorageProvider::class);
        $presetProvider->method('getOrCreate')->willReturn($preset);

        $settings = $this->createStub(AppSettingsProvider::class);
        $settings->method('getLastDownloadStorageId')->willReturn(2);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['selectedStorageId'] === 2
                    && \count($params['storages']) === 2,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(storageRepository: $storageRepository, presetStorageProvider: $presetProvider, settings: $settings, twig: $twig);

        $controller->new(Request::create('/downloads/new'));
    }

    public function testStorageOptionsFallBackToPresetWhenLastDownloadStorageIdIsDeleted(): void
    {
        $preset = $this->makeStorage(1);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findAllScannable')->willReturn([]);

        $presetProvider = $this->createStub(PresetDownloadsStorageProvider::class);
        $presetProvider->method('getOrCreate')->willReturn($preset);

        $settings = $this->createStub(AppSettingsProvider::class);
        $settings->method('getLastDownloadStorageId')->willReturn(99);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['selectedStorageId'] === 1,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(storageRepository: $storageRepository, presetStorageProvider: $presetProvider, settings: $settings, twig: $twig);

        $controller->new(Request::create('/downloads/new'));
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function presetFailureProvider(): iterable
    {
        yield 'runtime' => [new \RuntimeException('mkdir failed')];
        yield 'unavailable' => [new DownloadStorageUnavailableException(5, '/downloads/AnimeDB')];
    }

    #[DataProvider('presetFailureProvider')]
    public function testPresetFailureHidesPresetAndWarns(\Throwable $failure): void
    {
        $writable = $this->makeStorage(2);
        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findAllScannable')->willReturn([$writable]);

        $presetProvider = $this->createStub(PresetDownloadsStorageProvider::class);
        $presetProvider->method('getOrCreate')->willThrowException($failure);

        $settings = $this->createStub(AppSettingsProvider::class);
        $settings->method('getLastDownloadStorageId')->willReturn(2);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['presetFailed'] === true
                    && $params['storages'] === [$writable]
                    && $params['selectedStorageId'] === 2,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(storageRepository: $storageRepository, presetStorageProvider: $presetProvider, settings: $settings, twig: $twig);

        $this->assertSame(200, $controller->new(Request::create('/downloads/new'))->getStatusCode());
    }

    #[DataProvider('presetFailureProvider')]
    public function testPresetFailurePreselectsNothingWithoutValidLastStorage(\Throwable $failure): void
    {
        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findAllScannable')->willReturn([$this->makeStorage(2)]);

        $presetProvider = $this->createStub(PresetDownloadsStorageProvider::class);
        $presetProvider->method('getOrCreate')->willThrowException($failure);

        $settings = $this->createStub(AppSettingsProvider::class);
        $settings->method('getLastDownloadStorageId')->willReturn(99);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['selectedStorageId'] === null
                    && $params['presetFailed'] === true,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(storageRepository: $storageRepository, presetStorageProvider: $presetProvider, settings: $settings, twig: $twig);

        $controller->new(Request::create('/downloads/new'));
    }

    public function testPresetFailureOnFormErrorAfterPostStillRenders(): void
    {
        $presetProvider = $this->createStub(PresetDownloadsStorageProvider::class);
        $presetProvider->method('getOrCreate')->willThrowException(new \RuntimeException('mkdir failed'));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['presetFailed'] === true
                    && $params['error'] === 'download_new.error_anime_required',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(presetStorageProvider: $presetProvider, twig: $twig);

        $response = $controller->create($this->magnetRequest(['anime' => '0']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testPresetSuccessShowsNoWarning(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('downloads/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['presetFailed'] === false
                    && $params['selectedStorageId'] === 1,
            ))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig)->new(Request::create('/downloads/new'));
    }

    #[DataProvider('presetFailureProvider')]
    public function testPresetFailureIsLoggedAsWarningWithException(\Throwable $failure): void
    {
        $presetProvider = $this->createStub(PresetDownloadsStorageProvider::class);
        $presetProvider->method('getOrCreate')->willThrowException($failure);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->isString(), ['exception' => $failure]);

        $this->createController(presetStorageProvider: $presetProvider, logger: $logger)->new(Request::create('/downloads/new'));
    }
}
