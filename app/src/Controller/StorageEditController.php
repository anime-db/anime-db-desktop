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
use App\Repository\DownloadRepository;
use App\Service\AppSettingsProvider;
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
 * (Storage::rename()), relocating its path (Storage::relocate(), issue #654), and changing its
 * type (Storage::setType()) reuse the same validation the entity already enforces at
 * construction time. Relocating here is a plain manual edit — the user types the new path and
 * confirms it themselves; it does not search for or guess a replacement, unlike the by-marker
 * reconnect flow (issues #150/#162), which stays a separate mechanism for drive-letter
 * reassignment. Relocating away from a path also calls StorageMarkerService::forget() on the
 * old path, the same call StorageController::delete() makes, so a stale [AnimeDB] id doesn't
 * linger there for a future storage to collide with (StorageMarkerService::reconcile()'s
 * Conflict case). If the new type is writable, StorageMarkerService::reconcile() is invoked the
 * same way StorageNewController does on create, so a storage switched to a writable type — or
 * relocated to a new path — still ends up with a desktop.ini marker without requiring a rescan
 * first.
 *
 * Issue #853: relocating, or switching to a {@see StorageType::isWritable()}-false type, is
 * refused with a form error instead while the storage still has a not-yet-Completed downloads
 * row targeting it (same reasoning as {@see StorageController::delete()}). The preset downloads
 * storage (see AppSettingsProvider::getPresetDownloadsStorageId()) additionally never accepts a
 * relocate, or a switch to a non-writable type, regardless of downloads — {@see
 * PresetDownloadsStorageProvider::getOrCreate()} hands this storage out by id with no type check
 * of its own, so letting it become non-writable would silently break every future enqueue. Its
 * path field is rendered read-only, and its type select has every non-writable option disabled,
 * for the same reason the server-side checks exist: the server check is the actual guard, these
 * are just there so the form does not invite a submission the server is going to refuse anyway.
 */
final class StorageEditController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StorageMarkerService $markerService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly DownloadRepository $downloads,
        private readonly AppSettingsProvider $settings,
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
        $path = (string) $request->request->get('path', '');
        $type = StorageType::tryFrom((string) $request->request->get('type', ''));

        if ($type === null) {
            return $this->renderForm($storage, name: $name, path: $path, error: 'storage_edit.error_invalid');
        }

        $previousPath = $storage->getPath();
        $previousType = $storage->getType();
        $pathChanged = (trim($path) === '' ? null : trim($path)) !== $previousPath;
        $typeChangingToUnwritable = $type !== $previousType && !$type->isWritable();
        $isPreset = $storageId === $this->settings->getPresetDownloadsStorageId();

        if ($pathChanged && $isPreset) {
            return $this->renderForm(
                $storage,
                name: $name,
                path: $path,
                type: $type,
                error: 'storage_edit.error_preset_path',
                errorParams: ['%name%' => $storage->getName()],
            );
        }

        if ($typeChangingToUnwritable && $isPreset) {
            return $this->renderForm(
                $storage,
                name: $name,
                path: $path,
                type: $type,
                error: 'storage_edit.error_preset_type',
                errorParams: ['%name%' => $storage->getName()],
            );
        }

        if (($pathChanged || $typeChangingToUnwritable) && $this->downloads->hasUnfinishedDownloadsForTargetStorage($storageId)) {
            return $this->renderForm(
                $storage,
                name: $name,
                path: $path,
                type: $type,
                error: 'storage_edit.error_unfinished_downloads',
                errorParams: ['%name%' => $storage->getName()],
            );
        }

        try {
            $storage->rename($name);
            $storage->setType($type);
            $storage->relocate($path);
        } catch (InvalidNameException|InvalidPathException) {
            return $this->renderForm($storage, name: $name, path: $path, type: $type, error: 'storage_edit.error_invalid');
        }

        if ($storage->getPath() !== $previousPath) {
            if ($previousPath !== null) {
                $this->markerService->forget($storage, $previousPath);
            }
        }

        $this->entityManager->flush();

        if ($type->isWritable()) {
            $this->markerService->reconcile($storage);
        }

        return new RedirectResponse($this->urlGenerator->generate('storage_index'));
    }

    /** @param array<string, string> $errorParams */
    private function renderForm(
        Storage $storage,
        ?string $name = null,
        ?string $path = null,
        ?StorageType $type = null,
        ?string $error = null,
        array $errorParams = [],
    ): Response {
        return new Response($this->twig->render('storage/edit.html.twig', [
            'storage' => $storage,
            'name' => $name ?? $storage->getName(),
            'path' => $path ?? $storage->getPath() ?? '',
            'type' => ($type ?? $storage->getType())->value,
            'error' => $error,
            'errorParams' => $errorParams,
            'types' => array_column(StorageType::cases(), 'value'),
            'nonWritableTypes' => array_column(
                array_filter(StorageType::cases(), static fn (StorageType $type): bool => !$type->isWritable()),
                'value',
            ),
            'pathOptionalTypes' => array_column(
                array_filter(StorageType::cases(), static fn (StorageType $type): bool => !$type->isPathRequired()),
                'value',
            ),
            'pathNotApplicableTypes' => array_column(
                array_filter(StorageType::cases(), static fn (StorageType $type): bool => !$type->isReadable()),
                'value',
            ),
            'isPreset' => $storage->id !== null && $storage->id === $this->settings->getPresetDownloadsStorageId(),
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
