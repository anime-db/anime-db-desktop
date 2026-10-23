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
use App\Service\Storage\StorageIconService;
use App\Service\Storage\StorageMarkerResult;
use App\Service\Storage\StorageMarkerService;
use App\Service\Storage\StorageNameHinter;
use App\Service\Storage\StorageTypeResolver;
use App\Service\Storage\VolumeInfo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly StorageIconService $iconService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly StorageNameHinter $nameHinter,
        private readonly StorageTypeResolver $typeResolver,
    ) {
    }

    #[Route('/storage/new', name: 'storage_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        // Prefilled by the "create storage" link of an entry's Files block (issue #997).
        $path = (string) $request->query->get('path', '');
        // An unverified GET must not reach the filesystem or spawn a process (a drive root is
        // looked up only for the shell); the form's JS asks the guarded endpoint for that hint.
        $hint = $path === '' ? null : $this->nameHinter->hint($path, ShellRequestGuard::isShellRequest($request));

        // The type is not detected here for the same reason: only a UNC path, known from the string, is
        // rendered as text at once; a path with a drive letter shows "detecting" and the form's JS asks
        // the guarded endpoint.
        $type = $path === '' ? null : $this->typeResolver->typeFromString($path);
        $detecting = $path !== '' && $type === null && $this->typeResolver->driveLetter($path) !== null;

        return $this->renderForm(
            name: $hint ?? '',
            path: $path,
            type: $type,
            nameHint: $hint,
            typeAuto: $type !== null,
            typeDetecting: $detecting,
        );
    }

    /**
     * Suggested storage name and type for a path, as `{name: string|null, type: string|null}`. Only the desktop shell's own
     * window may ask (see {@see ShellRequestGuard}), and the check is the first thing done here.
     */
    #[Route('/storage/new/name-hint', name: 'storage_new_name_hint', methods: ['GET'])]
    public function nameHint(Request $request): Response
    {
        if (!ShellRequestGuard::isShellRequest($request)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $path = (string) $request->query->get('path', '');
        // One probe run answers both the drive label and the type. A failed probe is replaced with an
        // empty result for a drive path, so the name hinter does not start a second run.
        $volume = $this->typeResolver->probe($path);
        if ($volume === null && $this->typeResolver->driveLetter($path) !== null) {
            $volume = new VolumeInfo(null);
        }

        return new JsonResponse([
            'name' => $this->nameHinter->hint($path, true, $volume),
            'type' => $this->typeResolver->resolve($path, $volume)?->value,
        ]);
    }

    #[Route('/storage/new', name: 'storage_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertValidCsrfToken($request);

        $name = (string) $request->request->get('name', '');
        $path = (string) $request->request->get('path', '');
        $type = StorageType::tryFrom((string) $request->request->get('type', ''));
        // The type is never detected on POST; the form only repeats how it showed a type found earlier.
        $typeAuto = $type !== null && $request->request->get('type_source') === 'auto';

        if ($type === null) {
            return $this->renderForm(name: $name, path: $path, error: 'storage_new.error_invalid', typeAuto: $typeAuto);
        }

        try {
            $storage = new Storage($name, $path, $type);
        } catch (InvalidNameException|InvalidPathException) {
            return $this->renderForm(name: $name, path: $path, type: $type, error: 'storage_new.error_invalid', typeAuto: $typeAuto);
        }

        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $marker = $type->isWritable() ? $this->markerService->reconcile($storage) : null;

        if ($marker === StorageMarkerResult::Conflict) {
            // The folder already carries another storage's marker: this row would be a duplicate
            // that reports marker_conflict on every scan, so it is not kept (issue #997).
            $ownerId = $this->markerService->readMarkerId($storage->requirePath());
            $owner = $ownerId === null ? null : $this->entityManager->find(Storage::class, $ownerId);
            $this->entityManager->remove($storage);
            $this->entityManager->flush();

            return $this->renderForm(
                name: $name,
                path: $path,
                type: $type,
                error: 'storage_new.error_already_connected',
                errorParams: ['%name%' => $owner?->getName() ?? ''],
                existingStorageId: $owner?->id,
                typeAuto: $typeAuto,
            );
        }

        if ($marker !== null && $marker->ownsPath()) {
            $this->iconService->apply($storage);
        }

        $storageId = $storage->id ?? throw new \LogicException('Storage must be assigned an id right after flush().');

        return new RedirectResponse($this->urlGenerator->generate('storage_scan_prompt', ['id' => $storageId]));
    }

    /** @param array<string, string> $errorParams */
    private function renderForm(
        string $name = '',
        string $path = '',
        ?StorageType $type = null,
        ?string $error = null,
        array $errorParams = [],
        ?int $existingStorageId = null,
        ?string $nameHint = null,
        bool $typeAuto = false,
        bool $typeDetecting = false,
    ): Response {
        return new Response($this->twig->render('storage/new.html.twig', [
            'name' => $name,
            'path' => $path,
            'name_hint' => $nameHint,
            'type' => $type?->value,
            'type_auto' => $typeAuto,
            'type_detecting' => $typeDetecting,
            'error' => $error,
            'error_params' => $errorParams,
            'existing_storage_id' => $existingStorageId,
            'types' => array_column(StorageType::cases(), 'value'),
            'path_optional_types' => array_column(
                array_filter(StorageType::cases(), static fn (StorageType $type): bool => !$type->isPathRequired()),
                'value',
            ),
            'path_not_applicable_types' => array_column(
                array_filter(StorageType::cases(), static fn (StorageType $type): bool => !$type->isReadable()),
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
