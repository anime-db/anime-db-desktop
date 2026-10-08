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

use App\Entity\Storage;
use App\Message\ScanStorageMessage;
use App\Repository\DownloadRepository;
use App\Repository\StorageRepository;
use App\Service\AppSettingsProvider;
use App\Service\JobLock\JobLockService;
use App\Service\Storage\StorageAvailabilityService;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Minimal storage list and scan trigger (issue #136, Таск 3 часть 7.1): lists every known
 * Storage (name, type, path, last scan time) and dispatches ScanStorageMessage on the async
 * transport for a background scan. The redirect carries the scanned storage's id so the
 * template (issue #140, Таск 3 часть 7.5) can attach ScanWatcher (app/assets/js/scan.js) to
 * that storage_id and render its live progress/result screen without a page reload.
 *
 * Also handles storage deletion (issue #169, Таск 3 часть 8/CRUD 5).
 *
 * index() also flags storages whose path no longer exists on disk (issue #654: disconnected
 * drive, renamed folder, database moved from another machine) so the list surfaces them instead
 * of staying silent. The check runs here, once, when this page is opened — not in the
 * background and not at application startup — and never tries to guess a replacement path;
 * fixing it is left to the user via storage_edit (StorageEditController) or storage_delete above.
 *
 * The scan's own progress/confirmation UI moved to its own page (issue #834, {@see scanProgress()})
 * — this controller's index() no longer renders it, and no longer accepts the old `scanned`/
 * `storage_id` query parameters.
 */
final class StorageController
{
    public function __construct(
        private readonly StorageRepository $storages,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly StorageMarkerService $storageMarker,
        private readonly StorageAvailabilityService $storageAvailability,
        private readonly JobLockService $jobLockService,
        private readonly DownloadRepository $downloads,
        private readonly AppSettingsProvider $settings,
    ) {
    }

    #[Route('/storage', name: 'storage_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderIndex();
    }

    /** @param array<string, string> $errorParams */
    private function renderIndex(?string $error = null, array $errorParams = []): Response
    {
        $storages = $this->storages->findAllOrderedByName();

        return new Response($this->twig->render('storage/list.html.twig', [
            'storages' => $storages,
            'unavailableStorageIds' => $this->storageAvailability->unavailableStorageIds($storages),
            'presetStorageId' => $this->settings->getPresetDownloadsStorageId(),
            'error' => $error,
            'errorParams' => $errorParams,
        ]));
    }

    /**
     * Reads the configured storage paths so a caller can validate a filesystem path against
     * them without trusting a caller-supplied list of what's "allowed" — used by the desktop
     * shell's native process to check an IPC-supplied path before opening it (issue #593), not
     * intended for use from the rendered HTML pages.
     */
    #[Route('/storage/paths', name: 'storage_paths', methods: ['GET'])]
    public function paths(): JsonResponse
    {
        return new JsonResponse([
            'paths' => array_values(array_filter(array_map(
                static fn (Storage $storage): ?string => $storage->getPath(),
                $this->storages->findAllOrderedByName(),
            ), static fn (?string $path): bool => $path !== null)),
        ]);
    }

    #[Route('/storage/{id}/scan', name: 'storage_scan', methods: ['POST'])]
    public function scan(Storage $storage, Request $request): RedirectResponse
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before it can be scanned.');

        $this->assertValidCsrfToken('storage_scan_'.$storageId, $request);

        $this->messageBus->dispatch(new ScanStorageMessage($storageId));

        return new RedirectResponse($this->urlGenerator->generate('storage_scan_progress', ['id' => $storageId, 'started' => 1]));
    }

    /**
     * The scan progress/candidate-confirmation page (issue #834): a dedicated page outside
     * /settings, without the sidebar, so it reads as a step of adding entries to the catalog
     * rather than a settings screen (the same reasoning storage_scan_prompt already follows — see
     * base.html.twig's top-nav highlighting). Every trigger of a scan — this controller's own
     * {@see scan()}, the storage list's Scan button, and storage_scan_prompt's form — posts to
     * `storage_scan`, which redirects here with `?started=1`.
     *
     * The template's "is a scan actually running" state (`started`, despite the name) is true
     * when either {@see JobLockService::isLocked()} holds on {@see ScanStorageMessage::jobKey()}
     * — the same lock {@see \App\MessageHandler\ScanStorageMessageHandler} holds while scanning —
     * or the request carries `?started=1`, the one-shot marker {@see scan()} redirects here with
     * right after dispatching the message. The lock alone is not enough: it is only taken when
     * the async transport's consumer picks the message up, not when scan() enqueues it, so a
     * request landing in that gap would otherwise see no lock yet and render "scan not started"
     * even though a scan was just requested (issue #834 review). storage-scan.js strips `started`
     * from the URL once it mounts (via history.replaceState), so a later F5, back/forward
     * navigation, or bookmarked link carries no such marker and the lock alone decides — the
     * original "stuck claiming a scan is running" defect this flag was meant to fix stays fixed.
     */
    #[Route('/storage/{id}/scan-progress', name: 'storage_scan_progress', methods: ['GET'])]
    public function scanProgress(Storage $storage, Request $request): Response
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before its scan progress can be shown.');

        return new Response($this->twig->render('storage/scan_progress.html.twig', [
            'storage' => $storage,
            'started' => $request->query->getBoolean('started')
                || $this->jobLockService->isLocked(ScanStorageMessage::jobKey($storageId)),
        ]));
    }

    /**
     * Anime.storage_id is ON DELETE SET NULL (Version20260704000000, verified by
     * CatalogSchemaTest::testDeletingStorageSetsAnimeStorageIdToNull()), so removing the
     * Storage row here only detaches linked Anime records — it never deletes them. The
     * desktop.ini marker's [AnimeDB] id record is removed via StorageMarkerService::forget()
     * so the path is immediately free, rather than waiting for the "Reclaimed" branch of
     * StorageMarkerService::reconcile() on some future scan.
     *
     * Issue #853: refused outright, before forget() runs, when the storage is the preset
     * downloads storage (identified by id, see AppSettingsProvider::getPresetDownloadsStorageId())
     * or still has a downloads row targeting it that isn't Completed yet — qBittorrent may still be
     * writing into it, and downloads.target_storage_id is ON DELETE SET NULL, which would strand
     * that row with no root to compare its content_path against.
     */
    #[Route('/storage/{id}/delete', name: 'storage_delete', methods: ['POST'])]
    public function delete(Storage $storage, Request $request): Response
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before it can be deleted.');

        $this->assertValidCsrfToken('storage_delete_'.$storageId, $request);

        if ($storageId === $this->settings->getPresetDownloadsStorageId()) {
            return $this->renderIndex('storage_list.delete_error_preset', ['%name%' => $storage->getName()]);
        }

        if ($this->downloads->hasUnfinishedDownloadsForTargetStorage($storageId)) {
            return $this->renderIndex('storage_list.delete_error_unfinished_downloads', ['%name%' => $storage->getName()]);
        }

        $this->storageMarker->forget($storage);
        $this->entityManager->remove($storage);
        $this->entityManager->flush();

        return new RedirectResponse($this->urlGenerator->generate('storage_index'));
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
