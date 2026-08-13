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
 * Editing of an existing Storage (issue #168, Таск 3 часть 8/CRUD 4): renaming
 * (Storage::rename()) and changing its type (Storage::setType()) reuse the same
 * validation the entity already enforces at construction time. The path is intentionally not
 * editable here — a legitimate path change (drive letter reassigned) is already covered by the
 * by-marker reconnect flow (issues #150/#162), and manual path editing is out of scope for this
 * form. If the new type is writable, StorageMarkerService::reconcile() is invoked the same way
 * StorageNewController does on create, so a storage switched to a writable type still ends up
 * with a desktop.ini marker without requiring a rescan first.
 */
final class StorageEditController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StorageMarkerService $markerService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/storage/{id}/edit', name: 'storage_edit', methods: ['GET'])]
    public function edit(Storage $storage): Response
    {
        return $this->renderForm($storage);
    }

    #[Route('/storage/{id}/edit', name: 'storage_update', methods: ['POST'])]
    public function update(Storage $storage, Request $request): Response
    {
        $storageId = $storage->id ?? throw new \LogicException('Storage must be persisted before it can be edited.');

        $this->assertValidCsrfToken($storageId, $request);

        $name = (string) $request->request->get('name', '');
        $type = StorageType::tryFrom((string) $request->request->get('type', ''));

        if ($type === null) {
            return $this->renderForm($storage, name: $name, error: 'storage_edit.error_invalid');
        }

        try {
            $storage->rename($name);
        } catch (InvalidNameException) {
            return $this->renderForm($storage, name: $name, type: $type, error: 'storage_edit.error_invalid');
        }

        $storage->setType($type);

        $this->entityManager->flush();

        if ($type->isWritable()) {
            $this->markerService->reconcile($storage);
        }

        return new RedirectResponse($this->urlGenerator->generate('storage_index'));
    }

    private function renderForm(
        Storage $storage,
        ?string $name = null,
        ?StorageType $type = null,
        ?string $error = null,
    ): Response {
        return new Response($this->twig->render('storage/edit.html.twig', [
            'storage' => $storage,
            'name' => $name ?? $storage->getName(),
            'type' => ($type ?? $storage->getType())->value,
            'error' => $error,
            'types' => array_column(StorageType::cases(), 'value'),
        ]));
    }

    private function assertValidCsrfToken(int $storageId, Request $request): void
    {
        $token = new CsrfToken('storage_edit_'.$storageId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
