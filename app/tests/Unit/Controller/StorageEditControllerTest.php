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

use App\Controller\StorageEditController;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Repository\DownloadRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
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

    /** @var list<string> */
    private array $filesToClean = [];

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

        foreach ($this->filesToClean as $file) {
            if (is_file($file)) {
                unlink($file);
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

    /** A real AppSettingsProvider over a scratch config.json — it's final, so it can't be doubled. */
    private function createSettings(): AppSettingsProvider
    {
        $configPath = sys_get_temp_dir().'/storage-edit-test-config-'.uniqid().'.json';
        $this->filesToClean[] = $configPath;

        return new AppSettingsProvider(new AppConfigStore($configPath));
    }

    private function createController(
        ?EntityManagerInterface $entityManager = null,
        ?StorageMarkerService $markerService = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?Environment $twig = null,
        ?DownloadRepository $downloads = null,
        ?AppSettingsProvider $settings = null,
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

        if ($downloads === null) {
            $downloads = $this->createStub(DownloadRepository::class);
            $downloads->method('hasUnfinishedDownloadsForTargetStorage')->willReturn(false);
        }

        return new StorageEditController(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $markerService,
            $csrfTokenManager,
            $urlGenerator,
            $twig ?? $this->createStub(Environment::class),
            $downloads,
            $settings ?? $this->createSettings(),
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
            'path' => sys_get_temp_dir(),
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
            'path' => $dir,
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $controller->update($storage, $request);

        $marker = parse_ini_file($dir.\DIRECTORY_SEPARATOR.'desktop.ini', true, \INI_SCANNER_RAW);
        $this->assertIsArray($marker);
        $this->assertSame('8', $marker['AnimeDB']['id']);
    }

    public function testUpdateRelocatesStorageToNewExistingPath(): void
    {
        $missingPath = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'storage-edit-missing-'.uniqid();
        $newPath = $this->makeDir();
        $storage = new Storage('Main folder', $missingPath, StorageType::Folder);
        $this->setStorageId($storage, 9);

        $this->assertFalse(is_readable($storage->requirePath()));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController(entityManager: $entityManager);
        $request = Request::create('/storage/9/edit', 'POST', [
            'name' => 'Main folder',
            'path' => $newPath,
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $response = $controller->update($storage, $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame($newPath, $storage->getPath());
        $this->assertTrue(is_readable($storage->getPath()));
    }

    public function testUpdateRelocationForgetsMarkerAtOldPath(): void
    {
        $oldDir = $this->makeDir();
        $newDir = $this->makeDir();
        $storage = new Storage('Main folder', $oldDir, StorageType::ExternalR);
        $this->setStorageId($storage, 11);

        $markerEntityManager = $this->createStub(EntityManagerInterface::class);
        $markerEntityManager->method('find')->willReturn(null);
        $markerService = new StorageMarkerService($markerEntityManager);
        $markerService->reconcile($storage);

        $oldMarker = $oldDir.\DIRECTORY_SEPARATOR.'desktop.ini';
        $this->assertFileExists($oldMarker);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController(entityManager: $entityManager, markerService: $markerService);
        $request = Request::create('/storage/11/edit', 'POST', [
            'name' => 'Main folder',
            'path' => $newDir,
            'type' => 'external-r',
            '_token' => 'token',
        ]);

        $response = $controller->update($storage, $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame($newDir, $storage->getPath());
        $this->assertFileDoesNotExist($oldMarker);
    }

    public function testUpdateWithInvalidPathDoesNotFlushAndReRendersFormWithError(): void
    {
        $storage = new Storage('Main folder', sys_get_temp_dir(), StorageType::Folder);
        $this->setStorageId($storage, 5);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/edit.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_edit.error_invalid'
                    && $params['path'] === 'relative/path',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig);
        $request = Request::create('/storage/5/edit', 'POST', [
            'name' => 'Main folder',
            'path' => 'relative/path',
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $controller->update($storage, $request);

        $this->assertSame(sys_get_temp_dir(), $storage->getPath());
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

    /**
     * Acceptance (issue #853): relocating a storage that still has an unfinished (non-Completed)
     * download targeting it is refused — qBittorrent may still be writing under the old path,
     * and the jail compares content_path against whatever path is current when it finishes.
     */
    public function testUpdateRejectsPathChangeWhenStorageHasUnfinishedDownloads(): void
    {
        $oldDir = $this->makeDir();
        $newDir = $this->makeDir();
        $storage = new Storage('Main folder', $oldDir, StorageType::Folder);
        $this->setStorageId($storage, 20);

        $downloads = $this->createMock(DownloadRepository::class);
        $downloads->expects($this->once())
            ->method('hasUnfinishedDownloadsForTargetStorage')
            ->with(20)
            ->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/edit.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_edit.error_unfinished_downloads'
                    && $params['errorParams'] === ['%name%' => 'Main folder'],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig, downloads: $downloads);
        $request = Request::create('/storage/20/edit', 'POST', [
            'name' => 'Main folder',
            'path' => $newDir,
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $controller->update($storage, $request);

        $this->assertSame($oldDir, $storage->getPath());
    }

    /**
     * Acceptance (issue #853): switching to a non-writable type (StorageType::isWritable() ===
     * false) while a download is still targeting the storage is refused the same way a path
     * change is.
     */
    public function testUpdateRejectsTypeChangeToUnwritableWhenStorageHasUnfinishedDownloads(): void
    {
        $dir = $this->makeDir();
        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->setStorageId($storage, 21);

        $downloads = $this->createStub(DownloadRepository::class);
        $downloads->method('hasUnfinishedDownloadsForTargetStorage')->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/edit.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_edit.error_unfinished_downloads',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig, downloads: $downloads);
        $request = Request::create('/storage/21/edit', 'POST', [
            'name' => 'Main folder',
            'path' => $dir,
            'type' => 'video',
            '_token' => 'token',
        ]);

        $controller->update($storage, $request);

        $this->assertSame(StorageType::Folder, $storage->getType());
    }

    /**
     * Acceptance (issue #853): a change between two writable types (Folder => External) is
     * allowed even with an unfinished download — only a change TO a non-writable type is guarded.
     */
    public function testUpdateAllowsChangeBetweenWritableTypesEvenWithUnfinishedDownloads(): void
    {
        $dir = $this->makeDir();
        $storage = new Storage('Main folder', $dir, StorageType::Folder);
        $this->setStorageId($storage, 22);

        $downloads = $this->createStub(DownloadRepository::class);
        $downloads->method('hasUnfinishedDownloadsForTargetStorage')->willReturn(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController(entityManager: $entityManager, downloads: $downloads);
        $request = Request::create('/storage/22/edit', 'POST', [
            'name' => 'Main folder',
            'path' => $dir,
            'type' => 'external',
            '_token' => 'token',
        ]);

        $response = $controller->update($storage, $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(StorageType::External, $storage->getType());
    }

    /**
     * Acceptance (issue #853): the preset downloads storage's path can never change, regardless of
     * downloads — the DownloadRepository stub here always reports no unfinished downloads, so the
     * refusal can only be coming from the preset check.
     */
    public function testUpdateRejectsPathChangeForPresetStorageEvenWithoutDownloads(): void
    {
        $oldDir = $this->makeDir();
        $newDir = $this->makeDir();
        $storage = new Storage('AnimeDB', $oldDir, StorageType::Folder);
        $this->setStorageId($storage, 23);

        $settings = $this->createSettings();
        $settings->setPresetDownloadsStorageId(23);

        $downloads = $this->createStub(DownloadRepository::class);
        $downloads->method('hasUnfinishedDownloadsForTargetStorage')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/edit.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_edit.error_preset_path',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig, downloads: $downloads, settings: $settings);
        $request = Request::create('/storage/23/edit', 'POST', [
            'name' => 'AnimeDB',
            'path' => $newDir,
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $controller->update($storage, $request);

        $this->assertSame($oldDir, $storage->getPath());
    }

    /**
     * Acceptance (issue #853 review): the preset downloads storage can never switch to a
     * non-writable type either, regardless of downloads — the DownloadRepository stub here
     * always reports no unfinished downloads, so the refusal can only be coming from the preset
     * check. Without this guard, PresetDownloadsStorageProvider::getOrCreate() would keep handing
     * this storage out by id with no type check of its own, silently breaking every future
     * enqueue into it.
     */
    public function testUpdateRejectsTypeChangeToUnwritableForPresetStorageEvenWithoutDownloads(): void
    {
        $dir = $this->makeDir();
        $storage = new Storage('AnimeDB', $dir, StorageType::Folder);
        $this->setStorageId($storage, 26);

        $settings = $this->createSettings();
        $settings->setPresetDownloadsStorageId(26);

        $downloads = $this->createStub(DownloadRepository::class);
        $downloads->method('hasUnfinishedDownloadsForTargetStorage')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/edit.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'storage_edit.error_preset_type',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig, downloads: $downloads, settings: $settings);
        $request = Request::create('/storage/26/edit', 'POST', [
            'name' => 'AnimeDB',
            'path' => $dir,
            'type' => 'video',
            '_token' => 'token',
        ]);

        $controller->update($storage, $request);

        $this->assertSame(StorageType::Folder, $storage->getType());
    }

    /** Acceptance (issue #853): renaming the preset storage, without touching its path, is allowed. */
    public function testUpdateAllowsRenamingPresetStorage(): void
    {
        $dir = $this->makeDir();
        $storage = new Storage('AnimeDB', $dir, StorageType::Folder);
        $this->setStorageId($storage, 24);

        $settings = $this->createSettings();
        $settings->setPresetDownloadsStorageId(24);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController(entityManager: $entityManager, settings: $settings);
        $request = Request::create('/storage/24/edit', 'POST', [
            'name' => 'Renamed preset',
            'path' => $dir,
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $response = $controller->update($storage, $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('Renamed preset', $storage->getName());
    }

    /**
     * Acceptance (issue #853 review): a trailing space the browser/user adds to an otherwise
     * unchanged path must not be mistaken for a real path change — Storage::relocate() trims the
     * path anyway, so comparing the raw, untrimmed form value against the current path would
     * make the preset storage (whose path can never change) reject its own unmodified value.
     */
    public function testUpdateAllowsPresetPathWithTrailingWhitespaceWhenUnchanged(): void
    {
        $dir = $this->makeDir();
        $storage = new Storage('AnimeDB', $dir, StorageType::Folder);
        $this->setStorageId($storage, 27);

        $settings = $this->createSettings();
        $settings->setPresetDownloadsStorageId(27);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $controller = $this->createController(entityManager: $entityManager, settings: $settings);
        $request = Request::create('/storage/27/edit', 'POST', [
            'name' => 'AnimeDB',
            'path' => $dir.' ',
            'type' => 'folder',
            '_token' => 'token',
        ]);

        $response = $controller->update($storage, $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame($dir, $storage->getPath());
    }

    /** Acceptance (issue #853): the preset's path field is rendered read-only, not just refused server-side. */
    public function testEditMarksPresetStorageAsPresetForTheTemplate(): void
    {
        $storage = new Storage('AnimeDB', sys_get_temp_dir(), StorageType::Folder);
        $this->setStorageId($storage, 25);

        $settings = $this->createSettings();
        $settings->setPresetDownloadsStorageId(25);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/edit.html.twig', $this->callback(
                static fn (array $params): bool => $params['isPreset'] === true,
            ))
            ->willReturn('<html></html>');

        $this->createController(twig: $twig, settings: $settings)->edit($storage);
    }
}
