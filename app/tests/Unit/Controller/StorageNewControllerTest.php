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

namespace App\Tests\Unit\Controller;

use App\Controller\StorageNewController;
use App\Entity\Storage;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class StorageNewControllerTest extends TestCase
{
    /** @var list<string> */
    private array $dirsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->dirsToClean as $dir) {
            $marker = $dir.\DIRECTORY_SEPARATOR.'desktop.ini';
            if (is_file($marker)) {
                unlink($marker);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    private function makeDir(): string
    {
        $dir = sys_get_temp_dir().'/storage-new-test-'.uniqid();
        mkdir($dir, recursive: true);
        $this->dirsToClean[] = $dir;

        return $dir;
    }

    private function createController(
        ?EntityManagerInterface $entityManager = null,
        ?StorageMarkerService $markerService = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?Environment $twig = null,
    ): StorageNewController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/storage');
        }

        if ($markerService === null) {
            $markerEntityManager = $this->createStub(EntityManagerInterface::class);
            $markerEntityManager->method('find')->willReturn(null);
            $markerService = new StorageMarkerService($markerEntityManager);
        }

        return new StorageNewController(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $markerService,
            $csrfTokenManager,
            $urlGenerator,
            $twig ?? $this->createStub(Environment::class),
        );
    }

    /** @return EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function entityManagerAssigningId(int $id, ?Storage &$persistedStorage): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (Storage $storage) use (&$persistedStorage): bool {
                $persistedStorage = $storage;

                return true;
            }));
        $entityManager->expects($this->once())
            ->method('flush')
            ->willReturnCallback(function () use (&$persistedStorage, $id): void {
                (new \ReflectionProperty(Storage::class, 'id'))->setValue($persistedStorage, $id);
            });

        return $entityManager;
    }

    public function testNewRendersFormWithTypesAndWritableTypes(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['name'] === ''
                    && $params['path'] === ''
                    && $params['type'] === null
                    && $params['error'] === null
                    && ['folder', 'external', 'external-r', 'video'] === $params['types']
                    && ['folder', 'external'] === $params['writable_types'],
            ))
            ->willReturn('<html></html>');

        $response = $this->createController(twig: $twig)->new();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreatePersistsStorageAndWritesMarkerForWritableType(): void
    {
        $dir = $this->makeDir();
        $persistedStorage = null;
        $entityManager = $this->entityManagerAssigningId(7, $persistedStorage);

        $controller = $this->createController(entityManager: $entityManager);
        $request = Request::create('/storage/new', 'POST', [
            'name' => 'Main folder',
            'path' => $dir,
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $response = $controller->create($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $marker = parse_ini_file($dir.\DIRECTORY_SEPARATOR.'desktop.ini', true, \INI_SCANNER_RAW);
        $this->assertIsArray($marker);
        $this->assertSame('7', $marker['AnimeDB']['id']);
    }

    public function testCreateRedirectsToScanPromptWithNewStorageId(): void
    {
        $dir = $this->makeDir();
        $persistedStorage = null;
        $entityManager = $this->entityManagerAssigningId(11, $persistedStorage);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('storage_scan_prompt', ['id' => 11])
            ->willReturn('/storage/11/scan-prompt');

        $controller = $this->createController(entityManager: $entityManager, urlGenerator: $router);
        $request = Request::create('/storage/new', 'POST', [
            'name' => 'Main folder',
            'path' => $dir,
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $response = $controller->create($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/storage/11/scan-prompt', $response->getTargetUrl());
    }

    public function testCreateDoesNotWriteMarkerForNonWritableType(): void
    {
        $dir = $this->makeDir();
        $persistedStorage = null;
        $entityManager = $this->entityManagerAssigningId(9, $persistedStorage);

        $controller = $this->createController(entityManager: $entityManager);
        $request = Request::create('/storage/new', 'POST', [
            'name' => 'Read-only disc',
            'path' => $dir,
            'type' => 'external-r',
            '_token' => 'token',
        ]);

        $controller->create($request);

        $this->assertFileDoesNotExist($dir.\DIRECTORY_SEPARATOR.'desktop.ini');
    }

    public function testCreateWithEmptyNameDoesNotPersistAndReRendersFormWithError(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_new.error_invalid',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig);
        $request = Request::create('/storage/new', 'POST', [
            'name' => '   ',
            'path' => sys_get_temp_dir(),
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $controller->create($request);
    }

    public function testCreateWithInvalidPathDoesNotPersistAndReRendersFormWithError(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_new.error_invalid',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig);
        $request = Request::create('/storage/new', 'POST', [
            'name' => 'Main folder',
            'path' => 'relative/path',
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $controller->create($request);
    }

    public function testCreateWithInvalidTypeDoesNotPersistAndReRendersFormWithError(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_new.error_invalid',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig);
        $request = Request::create('/storage/new', 'POST', [
            'name' => 'Main folder',
            'path' => sys_get_temp_dir(),
            'type' => 'bogus',
            '_token' => 'token',
        ]);

        $controller->create($request);
    }

    public function testCreateRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $controller = $this->createController(entityManager: $entityManager, csrfTokenManager: $csrf);
        $request = Request::create('/storage/new', 'POST', [
            'name' => 'Main folder',
            'path' => sys_get_temp_dir(),
            'type' => 'folder',
            '_token' => 'bad',
        ]);

        $this->expectException(BadRequestHttpException::class);
        $controller->create($request);
    }
}
