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

namespace App\Tests\Unit\Controller;

use App\Controller\StorageEditController;
use App\Entity\Enum\StorageType;
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

final class StorageEditControllerTest extends TestCase
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
        $dir = sys_get_temp_dir().'/storage-edit-test-'.uniqid();
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
    ): StorageEditController {
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

        return new StorageEditController(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $markerService,
            $csrfTokenManager,
            $urlGenerator,
            $twig ?? $this->createStub(Environment::class),
        );
    }

    private function setStorageId(Storage $storage, int $id): void
    {
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);
    }

    public function testEditRendersFormWithCurrentNameAndType(): void
    {
        $storage = new Storage('Main folder', sys_get_temp_dir(), StorageType::Folder);
        $this->setStorageId($storage, 5);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/edit.html.twig', $this->callback(
                static fn (array $params): bool => $storage === $params['storage']
                    && $params['name'] === 'Main folder'
                    && $params['type'] === 'folder'
                    && $params['error'] === null
                    && ['folder', 'external', 'external-r', 'video'] === $params['types'],
            ))
            ->willReturn('<html></html>');

        $response = $this->createController(twig: $twig)->edit($storage);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateRenamesAndChangesTypeThenRedirects(): void
    {
        $storage = new Storage('Old name', sys_get_temp_dir(), StorageType::Folder);
        $this->setStorageId($storage, 5);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('storage_index')
            ->willReturn('/storage');

        $controller = $this->createController(entityManager: $entityManager, urlGenerator: $router);
        $request = Request::create('/storage/5/edit', 'POST', [
            'name' => 'New name',
            'type' => 'external-r',
            '_token' => 'token',
        ]);

        $response = $controller->update($storage, $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/storage', $response->getTargetUrl());
        $this->assertSame('New name', $storage->getName());
        $this->assertSame(StorageType::ExternalR, $storage->getType());
    }

    public function testUpdateWritesMarkerWhenNewTypeIsWritable(): void
    {
        $dir = $this->makeDir();
        $storage = new Storage('Main folder', $dir, StorageType::ExternalR);
        $this->setStorageId($storage, 8);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController(entityManager: $entityManager);
        $request = Request::create('/storage/8/edit', 'POST', [
            'name' => 'Main folder',
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $controller->update($storage, $request);

        $marker = parse_ini_file($dir.\DIRECTORY_SEPARATOR.'desktop.ini', true, \INI_SCANNER_RAW);
        $this->assertIsArray($marker);
        $this->assertSame('8', $marker['AnimeDB']['id']);
    }

    public function testUpdateWithEmptyNameDoesNotFlushAndReRendersFormWithError(): void
    {
        $storage = new Storage('Old name', sys_get_temp_dir(), StorageType::Folder);
        $this->setStorageId($storage, 5);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/edit.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_edit.error_invalid'
                    && $params['name'] === '   '
                    && $params['type'] === 'external',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig);
        $request = Request::create('/storage/5/edit', 'POST', [
            'name' => '   ',
            'type' => 'external',
            '_token' => 'token',
        ]);

        $controller->update($storage, $request);

        $this->assertSame('Old name', $storage->getName());
        $this->assertSame(StorageType::Folder, $storage->getType());
    }

    public function testUpdateWithInvalidTypeDoesNotFlushAndReRendersFormWithError(): void
    {
        $storage = new Storage('Old name', sys_get_temp_dir(), StorageType::Folder);
        $this->setStorageId($storage, 5);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/edit.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_edit.error_invalid',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig);
        $request = Request::create('/storage/5/edit', 'POST', [
            'name' => 'Old name',
            'type' => 'bogus',
            '_token' => 'token',
        ]);

        $controller->update($storage, $request);

        $this->assertSame(StorageType::Folder, $storage->getType());
    }

    public function testUpdateRejectsInvalidCsrfToken(): void
    {
        $storage = new Storage('Old name', sys_get_temp_dir(), StorageType::Folder);
        $this->setStorageId($storage, 5);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $controller = $this->createController(entityManager: $entityManager, csrfTokenManager: $csrf);
        $request = Request::create('/storage/5/edit', 'POST', [
            'name' => 'New name',
            'type' => 'folder',
            '_token' => 'bad',
        ]);

        $this->expectException(BadRequestHttpException::class);
        $controller->update($storage, $request);
    }
}
