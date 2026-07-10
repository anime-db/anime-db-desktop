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

use App\Controller\StorageController;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Message\ScanStorageMessage;
use App\Repository\StorageRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class StorageControllerTest extends TestCase
{
    private function createController(
        ?StorageRepository $storages = null,
        ?MessageBusInterface $messageBus = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?Environment $twig = null,
    ): StorageController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/storage?scanned=1');
        }

        if ($messageBus === null) {
            $messageBus = $this->createStub(MessageBusInterface::class);
            $messageBus->method('dispatch')->willReturnCallback(
                static fn (object $message): Envelope => new Envelope($message),
            );
        }

        return new StorageController(
            $storages ?? $this->createStub(StorageRepository::class),
            $messageBus,
            $csrfTokenManager,
            $urlGenerator,
            $twig ?? $this->createStub(Environment::class),
        );
    }

    public function testIndexPassesStoragesToTemplate(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);

        $storages = $this->createStub(StorageRepository::class);
        $storages->method('findAllOrderedByName')->willReturn([$storage]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('storage/list.html.twig', $this->callback(
                static fn (array $params): bool => [$storage] === $params['storages']
                    && $params['scanned'] === false
                    && $params['scannedStorageId'] === null,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(storages: $storages, twig: $twig);
        $response = $controller->index(Request::create('/storage'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testScanDispatchesScanStorageMessageAndRedirects(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                static fn (object $message): bool => $message instanceof ScanStorageMessage && $message->storageId === 42,
            ))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('storage_index', ['scanned' => 1, 'storage_id' => 42])
            ->willReturn('/storage?scanned=1&storage_id=42');

        $controller = $this->createController(messageBus: $messageBus, urlGenerator: $router);
        $request = Request::create('/storage/42/scan', 'POST', ['_token' => 'token']);

        $response = $controller->scan($storage, $request);

        $this->assertSame('/storage?scanned=1&storage_id=42', $response->getTargetUrl());
    }

    public function testScanRejectsInvalidCsrfToken(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $this->setStorageId($storage, 42);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $controller = $this->createController(messageBus: $messageBus, csrfTokenManager: $csrf);
        $request = Request::create('/storage/42/scan', 'POST', ['_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->scan($storage, $request);
    }

    private function setStorageId(Storage $storage, int $id): void
    {
        $property = new \ReflectionProperty(Storage::class, 'id');
        $property->setValue($storage, $id);
    }
}
