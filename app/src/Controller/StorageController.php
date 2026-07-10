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

use App\Entity\Storage;
use App\Message\ScanStorageMessage;
use App\Repository\StorageRepository;
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
 * transport for a background scan. WS progress/result screens are a separate part (7.4/7.5) —
 * this only starts the job and redirects back with a query-param flash, same convention as
 * Settings\LabelController.
 */
final class StorageController
{
    public function __construct(
        private readonly StorageRepository $storages,
        private readonly MessageBusInterface $messageBus,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/storage', name: 'storage_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return new Response($this->twig->render('storage/list.html.twig', [
            'storages' => $this->storages->findAllOrderedByName(),
            'scanned' => $request->query->getBoolean('scanned'),
        ]));
    }

    #[Route('/storage/{id}/scan', name: 'storage_scan', methods: ['POST'])]
    public function scan(Storage $storage, Request $request): RedirectResponse
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before it can be scanned.');

        $this->assertValidCsrfToken('storage_scan_'.$storageId, $request);

        $this->messageBus->dispatch(new ScanStorageMessage($storageId));

        return new RedirectResponse($this->urlGenerator->generate('storage_index', ['scanned' => 1]));
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
