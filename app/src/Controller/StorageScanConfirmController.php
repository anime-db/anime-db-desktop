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

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use App\Entity\Anime;
use App\Entity\Storage;
use App\Service\Storage\Exception\StoragePathConflictException;
use App\Service\Storage\Scan\ScanCandidate;
use App\Service\Storage\ScanStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Binds a scanned storage file to the single candidate the user picked out of a
 * ScanItemType::NeedsConfirmation item (issue #138, Таск 3 часть 7.3), by calling the same
 * ScanStorageService::linkToChosenCandidate()/linkToChosenPluginCandidate() the 0/1/>1 rule
 * (часть 5) uses for its own exactly-one-candidate case — this action just skips re-running
 * that rule.
 *
 * The scan result (часть 5) is not persisted anywhere (see #122), so the candidate the user
 * picked is not looked up again in the catalog: it comes back exactly as the frontend received
 * it in the scan.done payload (часть 7.5) — an anime_id for an orphan candidate, or a plugin
 * candidate's real pluginId/externalId/name (issue #832; see
 * ScanStorageMessageHandler::serializeCandidate()) for a plugin match.
 *
 * Dispatches {@see AnimeFilesChangedEvent} with {@see FilesChangeReason::PathChanged} once
 * flushed (issue #703/#684, часть 3): this is the one caller of
 * ScanStorageService::linkToChosenCandidate() outside the scan() algorithm itself, so the
 * dispatch lives here rather than inside ScanStorageService — that class's own scanning logic
 * stays untouched.
 */
final class StorageScanConfirmController
{
    public function __construct(
        private readonly ScanStorageService $scanStorageService,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[Route('/storage/{id}/scan/confirm', name: 'storage_scan_confirm', methods: ['POST'])]
    public function confirm(Storage $storage, Request $request): JsonResponse
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before its scan can be confirmed.');

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        $token = new CsrfToken('storage_scan_confirm_'.$storageId, (string) ($payload['token'] ?? ''));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $storagePath = $payload['storage_path'] ?? null;
        if (!\is_string($storagePath) || $storagePath === '') {
            throw new BadRequestHttpException('"storage_path" is required.');
        }

        $animeId = $payload['anime_id'] ?? null;
        if ($animeId !== null) {
            return $this->confirmOrphan($storage, $storagePath, $animeId);
        }

        return $this->confirmPlugin($storage, $storagePath, $payload);
    }

    private function confirmOrphan(Storage $storage, string $storagePath, mixed $animeId): JsonResponse
    {
        if (!\is_int($animeId)) {
            throw new BadRequestHttpException('"anime_id" must be an integer.');
        }

        $anime = $this->entityManager->find(Anime::class, $animeId);
        if (!$anime instanceof Anime) {
            throw new NotFoundHttpException(\sprintf('Anime #%d not found.', $animeId));
        }

        try {
            $anime = $this->scanStorageService->linkToChosenCandidate($storage, $storagePath, ScanCandidate::fromOrphan($anime));
        } catch (StoragePathConflictException $e) {
            // Unlike the plugin-candidate branch below, an anime_id candidate conflict keeps the
            // plain-message 409 it always had (issue #832's structured "conflict" body is scoped
            // to a record resolved by pluginId/externalId, not the pre-existing orphan path).
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return $this->respond($anime, $storagePath, filledFromPlugin: true);
    }

    /** @param array<string, mixed> $payload */
    private function confirmPlugin(Storage $storage, string $storagePath, array $payload): JsonResponse
    {
        $pluginId = $payload['plugin_id'] ?? null;
        $name = $payload['name'] ?? null;
        if (!\is_string($pluginId) || $pluginId === '' || !\is_string($name) || $name === '') {
            throw new BadRequestHttpException('Either "anime_id" or "plugin_id"+"name" must be provided.');
        }

        $externalId = $payload['external_id'] ?? '';
        if (!\is_string($externalId)) {
            throw new BadRequestHttpException('"external_id" must be a string.');
        }

        $plugin = new SearchByPluginCandidate($pluginId, $name, $externalId);

        try {
            // A single confirmed candidate is worth downloading its cover synchronously for
            // (issue #832, point 5) — unlike the storage scan's own bulk/auto-link path, which
            // must not block on however many items it is processing, this is exactly one.
            $result = $this->scanStorageService->linkToChosenPluginCandidate($storage, $storagePath, $plugin, downloadCoverSynchronously: true);
        } catch (StoragePathConflictException $e) {
            return $this->conflictResponse($e);
        }

        return $this->respond($result->anime, $storagePath, $result->filledFromPlugin);
    }

    /**
     * A candidate that resolved (by anime_id, or by pluginId/externalId, issue #832) to a
     * catalog record already linked to a different storage_path reports a structured 409 body —
     * {anime: {id, title}, storage_path: <the path it is already linked to>} — so the frontend
     * can point the user at the existing entry instead of showing a bare error. The other
     * conflict case this exception also carries (the *requested* storage_path already occupied
     * by a different Anime, issue #147) has no such structured info to report and keeps the
     * plain-message 409 it always had.
     */
    private function conflictResponse(StoragePathConflictException $e): JsonResponse
    {
        if ($e->anime === null) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return new JsonResponse([
            'conflict' => [
                'anime' => ['id' => $e->anime->id, 'title' => $e->anime->getTitle()],
                'storage_path' => $e->alreadyLinkedStoragePath,
            ],
        ], JsonResponse::HTTP_CONFLICT);
    }

    private function respond(Anime $anime, string $storagePath, bool $filledFromPlugin): JsonResponse
    {
        $this->entityManager->flush();

        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id once it has been flushed.');
        $this->eventDispatcher->dispatch(new AnimeFilesChangedEvent(new AnimeId($animeId), FilesChangeReason::PathChanged));

        return new JsonResponse([
            'storage_path' => $storagePath,
            'anime' => ['id' => $anime->id, 'title' => $anime->getTitle()],
            'filled_from_plugin' => $filledFromPlugin,
        ]);
    }
}
