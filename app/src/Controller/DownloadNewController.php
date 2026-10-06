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

namespace App\Controller;

use AnimeDb\PluginContracts\Download\DownloadAlreadyLinkedToAnotherAnimeException;
use AnimeDb\PluginContracts\Download\DownloadSource;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Entity\Download;
use App\Entity\Storage;
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Repository\StorageRepository;
use App\Service\AppSettingsProvider;
use App\Service\Download\PresetDownloadsStorageProvider;
use App\Service\Download\QbittorrentDownloadService;
use App\Service\Exception\DownloadAlreadyInClientException;
use App\Service\Exception\DownloadNotConfirmedException;
use App\Service\Exception\DownloadStorageNotWritableException;
use App\Service\Exception\DownloadStorageUnavailableException;
use App\Service\Exception\InsufficientDiskSpaceException;
use App\Service\Exception\InvalidTorrentFileException;
use App\Service\Exception\QbittorrentClientException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * "Add download" page (issue #855): the human entry point for a magnet link or a `.torrent` file,
 * picking a catalog entry and a storage, enqueued through
 * {@see QbittorrentDownloadService::enqueueTo()} (issue #851). The anime page only ever links here
 * with `?anime=<id>` to preselect a record — it never hosts its own copy of this form.
 *
 * The `CONTENT_LENGTH` check in {@see create()} runs BEFORE the CSRF check on purpose: once a
 * request's body exceeds `post_max_size`, PHP hands the request on with empty `$_POST`/`$_FILES`
 * (see native/php-ini.js, which raises both `upload_max_filesize` and `post_max_size` to 16M for
 * this form), so the ordinary CSRF check would fail first and show "invalid token" instead of the
 * real cause.
 *
 * A `.torrent` upload is moved (not copied) into a fresh, randomly named `*.torrent` temp path —
 * {@see DownloadSource::torrentFile()} only accepts a path
 * ending in that extension, and the temp file's basename is what
 * {@see QbittorrentDownloadService} reports to qBittorrent as the torrent's file name. The temp
 * file is deleted in a `finally` block regardless of how {@see enqueueTo()} resolves — the source
 * is never kept around once a submission has been handled, successfully or not.
 */
final class DownloadNewController
{
    private const int MAX_CONTENT_LENGTH_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly AnimeRepository $animeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly DownloadRepository $downloadRepository,
        private readonly PresetDownloadsStorageProvider $presetStorageProvider,
        private readonly QbittorrentDownloadService $downloadService,
        private readonly AppSettingsProvider $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/downloads/new', name: 'download_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $animeId = $request->query->getInt('anime');
        $anime = $animeId > 0 ? ($this->animeRepository->findByIds([$animeId])[$animeId] ?? null) : null;

        return $this->renderForm(selectedAnime: $anime);
    }

    #[Route('/downloads/new', name: 'download_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        // Must run before anything that reads $request->request/$request->files — see class
        // docblock for why this has to be checked ahead of the CSRF token.
        $contentLength = (int) $request->server->get('CONTENT_LENGTH', '0');
        if ($contentLength > self::MAX_CONTENT_LENGTH_BYTES) {
            return $this->renderForm(error: 'download_new.error_file_too_large');
        }

        $this->assertValidCsrfToken($request);

        $magnet = trim((string) $request->request->get('magnet', ''));
        $storageId = $request->request->getInt('storage');
        $animeId = $request->request->getInt('anime');

        $anime = $animeId > 0 ? ($this->animeRepository->findByIds([$animeId])[$animeId] ?? null) : null;
        if ($anime === null) {
            return $this->renderForm(error: 'download_new.error_anime_required', magnet: $magnet, selectedStorageId: $storageId);
        }

        $storage = $storageId > 0 ? $this->entityManager->find(Storage::class, $storageId) : null;
        if ($storage === null) {
            return $this->renderForm(error: 'download_new.error_storage_required', selectedAnime: $anime, magnet: $magnet);
        }

        $uploadedFile = $request->files->get('torrent_file');

        if ($uploadedFile instanceof UploadedFile && !$uploadedFile->isValid()) {
            $error = \in_array($uploadedFile->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? 'download_new.error_file_too_large'
                : 'download_new.error_unreadable_source';

            return $this->renderForm(error: $error, selectedAnime: $anime, selectedStorageId: $storageId, magnet: $magnet);
        }

        try {
            $built = $this->buildSource($magnet, $uploadedFile);
        } catch (\InvalidArgumentException) {
            return $this->renderForm(error: 'download_new.error_unreadable_source', selectedAnime: $anime, selectedStorageId: $storageId, magnet: $magnet);
        }

        if ($built === null) {
            return $this->renderForm(error: 'download_new.error_source_required', selectedAnime: $anime, selectedStorageId: $storageId);
        }

        return $this->enqueue($built, $anime, $storage, $magnet);
    }

    /**
     * @return array{0: DownloadSource, 1: ?string}|null the source and, for a `.torrent` upload,
     *                                                   the temp path to delete afterwards; null
     *                                                   when neither a magnet nor a file was given
     *
     * @throws \InvalidArgumentException if $magnet is not a well-formed magnet URI, or the
     *                                   uploaded file could not be moved into a temp path
     */
    private function buildSource(string $magnet, mixed $uploadedFile): ?array
    {
        if ($magnet !== '') {
            return [DownloadSource::magnet($magnet), null];
        }

        if ($uploadedFile instanceof UploadedFile) {
            $tempPath = sys_get_temp_dir().\DIRECTORY_SEPARATOR.bin2hex(random_bytes(16)).'.torrent';

            try {
                $uploadedFile->move(\dirname($tempPath), basename($tempPath));
            } catch (FileException $e) {
                throw new \InvalidArgumentException('Uploaded file could not be moved.', previous: $e);
            }

            return [DownloadSource::torrentFile($tempPath), $tempPath];
        }

        return null;
    }

    /**
     * @param array{0: DownloadSource, 1: ?string} $built
     */
    private function enqueue(array $built, Anime $anime, Storage $storage, string $magnet): Response
    {
        [$source, $tempPath] = $built;
        $animeId = $anime->id ?? throw new \LogicException('Anime must be persisted before a download can target it.');
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before a download can target it.');

        $existingInfoHashes = array_map(
            static fn (Download $download): string => $download->getInfoHash(),
            $this->downloadRepository->findByAnime($animeId),
        );

        try {
            $taskId = $this->downloadService->enqueueTo($source, new AnimeId($animeId), $storage);
        } catch (InvalidTorrentFileException) {
            return $this->renderForm(error: 'download_new.error_unreadable_source', selectedAnime: $anime, selectedStorageId: $storageId, magnet: $magnet);
        } catch (InsufficientDiskSpaceException $e) {
            return $this->renderForm(
                error: 'download_new.error_insufficient_space',
                errorParams: [
                    '%needed%' => $this->formatBytes($e->neededBytes),
                    '%free%' => $this->formatBytes($e->freeBytes),
                    '%path%' => $e->storagePath,
                ],
                selectedAnime: $anime,
                selectedStorageId: $storageId,
                magnet: $magnet,
            );
        } catch (DownloadAlreadyLinkedToAnotherAnimeException $e) {
            return $this->renderForm(
                error: 'download_new.error_already_linked',
                errorParams: ['%id%' => (string) $e->occupyingAnimeId->value],
                occupyingAnimeId: $e->occupyingAnimeId->value,
                selectedAnime: $anime,
                selectedStorageId: $storageId,
                magnet: $magnet,
            );
        } catch (DownloadAlreadyInClientException $e) {
            return $this->renderForm(
                error: 'download_new.error_already_in_client',
                downloadsLink: true,
                adoptInfoHash: $e->infoHash,
                selectedAnime: $anime,
                selectedStorageId: $storageId,
                magnet: $magnet,
            );
        } catch (DownloadStorageUnavailableException|DownloadStorageNotWritableException) {
            return $this->renderForm(error: 'download_new.error_storage_unavailable', selectedAnime: $anime, selectedStorageId: $storageId, magnet: $magnet);
        } catch (QbittorrentClientException|DownloadNotConfirmedException) {
            return $this->renderForm(error: 'download_new.error_client_unavailable', selectedAnime: $anime, selectedStorageId: $storageId, magnet: $magnet);
        } finally {
            if ($tempPath !== null) {
                @unlink($tempPath);
            }
        }

        $this->settings->setLastDownloadStorageId($storageId);

        if (\in_array($taskId->value, $existingInfoHashes, true)) {
            return $this->renderForm(info: 'download_new.info_already_in_downloads', selectedAnime: $anime, selectedStorageId: $storageId);
        }

        return new RedirectResponse($this->urlGenerator->generate('downloads_index'));
    }

    /**
     * @param array<string, string> $errorParams
     */
    private function renderForm(
        ?Anime $selectedAnime = null,
        ?int $selectedStorageId = null,
        string $magnet = '',
        ?string $error = null,
        array $errorParams = [],
        ?string $info = null,
        ?int $occupyingAnimeId = null,
        bool $downloadsLink = false,
        ?string $adoptInfoHash = null,
    ): Response {
        [$storages, $defaultStorageId, $presetFailed] = $this->buildStorageOptions();

        return new Response($this->twig->render('downloads/new.html.twig', [
            'selectedAnime' => $selectedAnime,
            'magnet' => $magnet,
            'storages' => $storages,
            'presetFailed' => $presetFailed,
            'selectedStorageId' => $selectedStorageId ?? $defaultStorageId,
            'error' => $error,
            'errorParams' => $errorParams,
            'info' => $info,
            'occupyingAnimeId' => $occupyingAnimeId,
            'downloadsLink' => $downloadsLink,
            'adoptInfoHash' => $adoptInfoHash,
        ]));
    }

    /**
     * @return array{0: list<Storage>, 1: ?int, 2: bool} the writable storages plus the preset one
     *                                                   (de-duplicated by id, absent when it could not be prepared),
     *                                                   the id to preselect and whether the preset failed
     */
    private function buildStorageOptions(): array
    {
        $preset = null;
        $presetFailed = false;
        try {
            $preset = $this->presetStorageProvider->getOrCreate();
        } catch (\RuntimeException $e) {
            $presetFailed = true;
            $this->logger->warning('Could not prepare the preset downloads storage.', ['exception' => $e]);
        }

        $byId = [];
        foreach ($this->storageRepository->findAllScannable() as $storage) {
            $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted.');
            $byId[$storageId] = $storage;
        }

        $presetId = null;
        if ($preset !== null) {
            $presetId = $preset->id ?? throw new \LogicException('Preset storage must be persisted.');
            $byId[$presetId] = $preset;
        }

        $defaultId = $this->settings->getLastDownloadStorageId();
        if ($defaultId === null || !isset($byId[$defaultId])) {
            $defaultId = $presetId;
        }

        return [array_values($byId), $defaultId, $presetFailed];
    }

    /**
     * Mirrors {@see \App\Service\Download\DownloadsOverviewBuilder::formatBytes()}'s unit choice
     * and translation keys so a disk-space message reads consistently with the Downloads page.
     */
    private function formatBytes(int $bytes): string
    {
        $unitKeys = ['downloads.unit_b', 'downloads.unit_kb', 'downloads.unit_mb', 'downloads.unit_gb', 'downloads.unit_tb'];
        $value = (float) max(0, $bytes);

        $unitIndex = 0;
        while ($value >= 1024.0 && $unitIndex < \count($unitKeys) - 1) {
            $value /= 1024.0;
            ++$unitIndex;
        }

        $decimals = $unitIndex === 0 ? 0 : 1;
        $formatter = new \NumberFormatter($this->translator->getLocale(), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        return $this->translator->trans($unitKeys[$unitIndex], ['%value%' => $formatter->format($value)]);
    }

    private function assertValidCsrfToken(Request $request): void
    {
        $token = new CsrfToken('download_new', (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
