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

use App\Controller\ExplorerOpenController;
use App\Repository\AnimeRepository;
use App\Repository\StorageRepository;
use App\Service\Storage\StorageLocator;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The shell-header guard of {@see ExplorerOpenController} (issue #1121): refused before the storage lookup and the filesystem. */
final class ExplorerOpenControllerTest extends TestCase
{
    private string $dir;
    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        // A marker in the folder: whoever reads it (the lookup does) must then ask the database for the storage.
        $this->dir = sys_get_temp_dir().'/explorer-open-unit-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/desktop.ini', "[AnimeDB]\nid=1\n");
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/desktop.ini');
        @rmdir($this->dir);
    }

    public function testWithoutHeaderNothingIsLookedUp(): void
    {
        $this->entityManager->expects(self::never())->method(self::anything());

        foreach ([$this->dir, '\\\\host\\x'] as $path) {
            $response = $this->controller()->open(Request::create('/explorer/open', 'GET', ['path' => $path]));

            self::assertSame(403, $response->getStatusCode());
        }
    }

    public function testForeignHostWithRightHeaderNothingIsLookedUp(): void
    {
        $this->entityManager->expects(self::never())->method(self::anything());

        foreach ([$this->dir, '\\\\host\\x'] as $path) {
            $request = Request::create('http://evil.example:8000/explorer/open', 'GET', ['path' => $path]);
            $request->headers->set(ExplorerOpenController::SHELL_HEADER, ExplorerOpenController::SHELL_HEADER_VALUE);

            self::assertSame(403, $this->controller()->open($request)->getStatusCode());
        }
    }

    public function testWithWrongHeaderValueNothingIsLookedUp(): void
    {
        $this->entityManager->expects(self::never())->method(self::anything());
        $request = Request::create('/explorer/open', 'GET', ['path' => $this->dir]);
        $request->headers->set(ExplorerOpenController::SHELL_HEADER, '0');

        self::assertSame(403, $this->controller()->open($request)->getStatusCode());
    }

    public function testWithHeaderTheLookupReachesTheDatabase(): void
    {
        // Keeps the two tests above honest: the very same request does reach the database once the header is present.
        $this->entityManager->expects(self::atLeastOnce())->method('find')->willReturn(null);
        $this->entityManager->method('getRepository')->willThrowException(new \RuntimeException('stop after the lookup'));
        $request = Request::create('/explorer/open', 'GET', ['path' => $this->dir]);
        $request->headers->set(ExplorerOpenController::SHELL_HEADER, ExplorerOpenController::SHELL_HEADER_VALUE);

        try {
            $this->controller()->open($request);
        } catch (\Throwable) {
            // the lookup after the marker climb is not under test
        }
    }

    private function controller(): ExplorerOpenController
    {
        return new ExplorerOpenController(
            new StorageLocator(
                new StorageRepository($this->entityManager),
                new StorageMarkerService($this->entityManager),
                $this->entityManager,
            ),
            new AnimeRepository($this->entityManager),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(TranslatorInterface::class),
        );
    }
}
