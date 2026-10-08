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

use App\Entity\Enum\StorageType;
use App\Entity\Exception\InvalidNameException;
use App\Entity\Exception\InvalidPathException;
use App\Entity\Storage;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Creation of a new Storage (issue #166, Таск 3 часть 8/CRUD 3): the constructor
 * (Storage::__construct()) already validates name/path, so this controller only wires the
 * form to it. For a writable type (StorageType::isWritable(), issue #164), the desktop.ini
 * marker is written right after persist()/flush() via StorageMarkerService::reconcile() — the
 * same reconciliation ScanStorageService::scan() performs — so issue #162's by-marker lookup
 * can find this storage even if its drive letter changes before the first scan ever runs.
 */
final class StorageNewController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StorageMarkerService $markerService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/storage/new', name: 'storage_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->renderForm();
    }

    #[Route('/storage/new', name: 'storage_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertValidCsrfToken($request);

        $name = (string) $request->request->get('name', '');
        $path = (string) $request->request->get('path', '');
        $type = StorageType::tryFrom((string) $request->request->get('type', ''));

        if ($type === null) {
            return $this->renderForm(name: $name, path: $path, error: 'storage_new.error_invalid');
        }

        try {
            $storage = new Storage($name, $path, $type);
        } catch (InvalidNameException|InvalidPathException) {
            return $this->renderForm(name: $name, path: $path, type: $type, error: 'storage_new.error_invalid');
        }

        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        if ($type->isWritable()) {
            $this->markerService->reconcile($storage);
        }

        $storageId = $storage->id ?? throw new \LogicException('Storage must be assigned an id right after flush().');

        return new RedirectResponse($this->urlGenerator->generate('storage_scan_prompt', ['id' => $storageId]));
    }

    private function renderForm(
        string $name = '',
        string $path = '',
        ?StorageType $type = null,
        ?string $error = null,
    ): Response {
        return new Response($this->twig->render('storage/new.html.twig', [
            'name' => $name,
            'path' => $path,
            'type' => $type?->value,
            'error' => $error,
            'types' => array_column(StorageType::cases(), 'value'),
            'path_optional_types' => array_column(
                array_filter(StorageType::cases(), static fn (StorageType $type): bool => !$type->isPathRequired()),
                'value',
            ),
            'writable_types' => array_values(array_map(
                static fn (StorageType $type): string => $type->value,
                array_filter(StorageType::cases(), static fn (StorageType $type): bool => $type->isWritable()),
            )),
        ]));
    }

    private function assertValidCsrfToken(Request $request): void
    {
        $token = new CsrfToken('storage_new', (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
