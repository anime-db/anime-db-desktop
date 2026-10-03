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
use App\Repository\StorageRepository;
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
    ) {
    }

    #[Route('/storage', name: 'storage_index', methods: ['GET'])]
    public function index(): Response
    {
        $storages = $this->storages->findAllOrderedByName();

        return new Response($this->twig->render('storage/list.html.twig', [
            'storages' => $storages,
            'unavailableStorageIds' => $this->storageAvailability->unavailableStorageIds($storages),
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
            'paths' => array_map(
                static fn (Storage $storage): string => $storage->getPath(),
                $this->storages->findAllOrderedByName(),
            ),
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
     * The template's "is a scan actually running" state (`started`, despite the name) is read from
     * {@see JobLockService::isLocked()} on {@see ScanStorageMessage::jobKey()} — the same lock
     * {@see \App\MessageHandler\ScanStorageMessageHandler} holds while scanning — rather than from
     * the `?started=1` query string (issue #834 review): that parameter only reflects the moment
     * the redirect was built, so F5, a back/forward navigation, or a bookmarked link kept it
     * claiming a scan was running long after it had already finished, and the page sat mounting
     * storage-scan.js's 15-second no-response timeout for a scan that would never report in. A
     * bare GET with no lock held — whether `started` is present or not — renders the static "scan
     * not started" prompt instead.
     */
    #[Route('/storage/{id}/scan-progress', name: 'storage_scan_progress', methods: ['GET'])]
    public function scanProgress(Storage $storage): Response
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before its scan progress can be shown.');

        return new Response($this->twig->render('storage/scan_progress.html.twig', [
            'storage' => $storage,
            'started' => $this->jobLockService->isLocked(ScanStorageMessage::jobKey($storageId)),
        ]));
    }

    /**
     * Anime.storage_id is ON DELETE SET NULL (Version20260704000000, verified by
     * CatalogSchemaTest::testDeletingStorageSetsAnimeStorageIdToNull()), so removing the
     * Storage row here only detaches linked Anime records — it never deletes them. The
     * desktop.ini marker's [AnimeDB] id record is removed via StorageMarkerService::forget()
     * so the path is immediately free, rather than waiting for the "Reclaimed" branch of
     * StorageMarkerService::reconcile() on some future scan.
     */
    #[Route('/storage/{id}/delete', name: 'storage_delete', methods: ['POST'])]
    public function delete(Storage $storage, Request $request): RedirectResponse
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before it can be deleted.');

        $this->assertValidCsrfToken('storage_delete_'.$storageId, $request);

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
