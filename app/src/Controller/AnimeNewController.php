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
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Repository\AnimeRepository;
use App\Service\Storage\Scan\ScanItemResolver;
use App\Service\Storage\TopLevelEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/**
 * Manual creation of a new Anime record (issue #137, Таск 3 часть 7.2): there was no way to
 * add a record to the catalog other than through the storage scanner's auto-link path
 * (ScanStorageService::linkToChosenCandidate()). The form only covers title, type and watch_status —
 * every other field (genres, studios, dates, ...) is edited afterwards through the existing
 * inline mechanism (#101/#103).
 *
 * Also serves the "0 candidates -> create manually" step of the scan flow (часть 7.5, not
 * built yet): the optional title/storage_id/storage_path query params prefill the form, and
 * when both storage params are present on submit the new record is linked to that file the
 * same way ScanStorageService::linkToChosenCandidate() links a plugin candidate.
 *
 * Dispatches {@see AnimeFilesChangedEvent} with {@see FilesChangeReason::Created} once the new
 * record has been flushed (issue #703/#684, часть 3): a plugin reacting to a record's files
 * needs this signal regardless of whether the create form also linked a storage path.
 */
final class AnimeNewController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AnimeRepository $animes,
    ) {
    }

    #[Route('/anime/new', name: 'anime_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $storageId = $request->query->get('storage_id');
        $storagePath = $request->query->get('storage_path');
        $storage = $this->findLinkTarget($storageId, $storagePath);

        return $this->renderForm(
            title: (string) $request->query->get('title', ''),
            storageId: $storageId,
            storagePath: $storagePath,
            error: $storage !== null ? $this->linkRefusal($storage, (string) $storagePath) : null,
        );
    }

    #[Route('/anime/new', name: 'anime_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertValidCsrfToken($request);

        $title = trim((string) $request->request->get('title', ''));
        $type = AnimeType::tryFrom((string) $request->request->get('type', ''));
        $watchStatus = WatchStatus::tryFrom((string) $request->request->get('watch_status', ''));
        $storageId = $request->request->get('storage_id');
        $storagePath = $request->request->get('storage_path');

        if ($title === '' || $type === null || $watchStatus === null) {
            return $this->renderForm(
                title: $title,
                type: $type,
                watchStatus: $watchStatus,
                storageId: $storageId,
                storagePath: $storagePath,
                error: 'anime_new.error_invalid',
            );
        }

        $entityClass = $type->entityClass();
        $anime = new $entityClass();
        $anime->setTitle($title)->setWatchStatus($watchStatus);

        if (\is_string($storageId) && $storageId !== '' && \is_string($storagePath) && $storagePath !== '') {
            $storage = $this->findLinkTarget($storageId, $storagePath);
            if ($storage !== null) {
                $refusal = $this->linkRefusal($storage, $storagePath);
                if ($refusal !== null) {
                    return $this->renderForm(
                        title: $title,
                        type: $type,
                        watchStatus: $watchStatus,
                        storageId: $storageId,
                        storagePath: $storagePath,
                        error: $refusal,
                    );
                }

                $anime->setStorage($storage)->setStoragePath($storagePath);
            }
        }

        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id once it has been flushed.');
        $this->eventDispatcher->dispatch(new AnimeFilesChangedEvent(new AnimeId($animeId), FilesChangeReason::Created));

        return new RedirectResponse($this->urlGenerator->generate('anime_show', ['id' => $anime->id]));
    }

    private function findLinkTarget(mixed $storageId, mixed $storagePath): ?Storage
    {
        if (!\is_string($storageId) || $storageId === '' || !\is_string($storagePath) || $storagePath === '') {
            return null;
        }

        $storage = $this->entityManager->find(Storage::class, $storageId);

        return $storage instanceof Storage ? $storage : null;
    }

    /**
     * Why the record cannot be linked to the folder, as a translation key (issue #998): the folder
     * is gone, or another record already holds the pair — which the unique index would otherwise
     * turn into a 500 on flush.
     */
    private function linkRefusal(Storage $storage, string $storagePath): ?string
    {
        if (!TopLevelEntry::exists($storage, $storagePath)) {
            return 'anime_new.error_entry_missing';
        }

        $key = ScanItemResolver::pathKey($storagePath);
        foreach ($this->animes->findStoragePathsByStorageId($storage->id ?? 0) as $held) {
            if (ScanItemResolver::pathKey($held) === $key) {
                return 'anime_new.error_storage_path_taken';
            }
        }

        return null;
    }

    private function renderForm(
        string $title = '',
        ?AnimeType $type = null,
        ?WatchStatus $watchStatus = null,
        mixed $storageId = null,
        mixed $storagePath = null,
        ?string $error = null,
    ): Response {
        return new Response($this->twig->render('anime/new.html.twig', [
            'title' => $title,
            'type' => $type?->value,
            'watch_status' => $watchStatus->value ?? WatchStatus::Plan->value,
            'storage_id' => $storageId,
            'storage_path' => $storagePath,
            'error' => $error,
            'types' => array_column(AnimeType::cases(), 'value'),
            'watch_statuses' => array_column(WatchStatus::cases(), 'value'),
        ]));
    }

    private function assertValidCsrfToken(Request $request): void
    {
        $token = new CsrfToken('anime_new', (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
