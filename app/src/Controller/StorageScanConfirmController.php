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

namespace App\Controller;

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

/**
 * Binds a scanned storage file to the single candidate the user picked out of a
 * ScanItemType::NeedsConfirmation item (issue #138, Таск 3 часть 7.3), by calling the same
 * ScanStorageService::linkToChosenCandidate() the 0/1/>1 rule (часть 5) uses for its own
 * exactly-one-candidate case — this action just skips re-running that rule.
 *
 * The scan result (часть 5) is not persisted anywhere (see #122), so the candidate the user
 * picked is not looked up again in the catalog: it comes back exactly as the frontend received
 * it in the scan.done payload (часть 7.5) — an anime_id for an orphan candidate, or a plugin
 * candidate's bare name (see ScanStorageMessageHandler::serializeCandidate()).
 */
final class StorageScanConfirmController
{
    /**
     * Placeholder plugin id for a candidate confirmed straight from the user rather than found
     * by a real plugin. No installed filler is ever registered under this id, so
     * BulkFillerService::fillNewFromPlugin() always reports "nothing to fill in" for it and
     * ScanStorageService::linkToChosenCandidate() falls back to its title-only placeholder —
     * this id only needs to be well-formed, not resolvable to anything real.
     */
    private const CONFIRMED_PLUGIN_ID = 'storage-scan-confirmed';

    public function __construct(
        private readonly ScanStorageService $scanStorageService,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
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

        $candidate = $this->resolveCandidate($payload['anime_id'] ?? null, $payload['name'] ?? null);

        try {
            $anime = $this->scanStorageService->linkToChosenCandidate($storage, $storagePath, $candidate);
        } catch (StoragePathConflictException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }
        $this->entityManager->flush();

        return new JsonResponse([
            'storage_path' => $storagePath,
            'anime' => ['id' => $anime->id, 'title' => $anime->getTitle()],
        ]);
    }

    private function resolveCandidate(mixed $animeId, mixed $name): ScanCandidate
    {
        if ($animeId !== null) {
            if (!\is_int($animeId)) {
                throw new BadRequestHttpException('"anime_id" must be an integer.');
            }

            $anime = $this->entityManager->find(Anime::class, $animeId);
            if (!$anime instanceof Anime) {
                throw new NotFoundHttpException(\sprintf('Anime #%d not found.', $animeId));
            }

            return ScanCandidate::fromOrphan($anime);
        }

        if (\is_string($name) && $name !== '') {
            return ScanCandidate::fromPlugin(new SearchByPluginCandidate(self::CONFIRMED_PLUGIN_ID, $name, ''));
        }

        throw new BadRequestHttpException('Either "anime_id" or "name" must be provided.');
    }
}
