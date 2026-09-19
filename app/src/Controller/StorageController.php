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
 * template (issue #140, Таск 3 часть 7.5) can attach ScanWatcher (app/public/js/scan.js) to
 * that storage_id and render its live progress/result screen without a page reload.
 *
 * Also handles storage deletion (issue #169, Таск 3 часть 8/CRUD 5).
 *
 * index() also flags storages whose path no longer exists on disk (issue #654: disconnected
 * drive, renamed folder, database moved from another machine) so the list surfaces them instead
 * of staying silent. The check runs here, once, when this page is opened — not in the
 * background and not at application startup — and never tries to guess a replacement path;
 * fixing it is left to the user via storage_edit (StorageEditController) or storage_delete above.
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
    ) {
    }

    #[Route('/storage', name: 'storage_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $storages = $this->storages->findAllOrderedByName();

        return new Response($this->twig->render('storage/list.html.twig', [
            'storages' => $storages,
            'unavailableStorageIds' => $this->unavailableStorageIds($storages),
            'scanned' => $request->query->getBoolean('scanned'),
            'scannedStorageId' => $request->query->get('storage_id'),
        ]));
    }

    /**
     * Same is_readable() check AnimeViewFactory::serializeStorage() already runs per-anime; here
     * it runs once per Storage row instead, so a missing drive shows on the storage it belongs to
     * rather than on every anime linked to it.
     *
     * @param Storage[] $storages
     *
     * @return list<int>
     */
    private function unavailableStorageIds(array $storages): array
    {
        $ids = [];
        foreach ($storages as $storage) {
            if (!is_readable($storage->getPath())) {
                $ids[] = $storage->id ?? throw new \LogicException('Storage must be persisted before its path can be checked.');
            }
        }

        return $ids;
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

        return new RedirectResponse($this->urlGenerator->generate('storage_index', ['scanned' => 1, 'storage_id' => $storageId]));
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
