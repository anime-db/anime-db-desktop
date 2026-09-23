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

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use App\Controller\AnimeNewController;
use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

final class AnimeNewControllerTest extends TestCase
{
    private function createController(
        ?EntityManagerInterface $entityManager = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?Environment $twig = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): AnimeNewController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/anime/1');
        }

        return new AnimeNewController(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $csrfTokenManager,
            $urlGenerator,
            $twig ?? $this->createStub(Environment::class),
            $eventDispatcher ?? $this->createStub(EventDispatcherInterface::class),
        );
    }

    /** @return EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function entityManagerAssigningId(int $id, ?Anime &$persistedAnime): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (Anime $anime) use (&$persistedAnime): bool {
                $persistedAnime = $anime;

                return true;
            }));
        $entityManager->expects($this->once())
            ->method('flush')
            ->willReturnCallback(function () use (&$persistedAnime, $id): void {
                (new \ReflectionProperty(Anime::class, 'id'))->setValue($persistedAnime, $id);
            });

        return $entityManager;
    }

    public function testNewRendersFormWithDefaultWatchStatusPlan(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['title'] === ''
                    && $params['type'] === null
                    && $params['watch_status'] === 'plan'
                    && $params['storage_id'] === null
                    && $params['storage_path'] === null,
            ))
            ->willReturn('<html></html>');

        $response = $this->createController(twig: $twig)->new(Request::create('/anime/new'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNewPrefillsTitleAndStorageFromQueryParameters(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['title'] === 'Frieren'
                    && $params['storage_id'] === '5'
                    && $params['storage_path'] === 'Frieren.mkv',
            ))
            ->willReturn('<html></html>');

        $request = Request::create('/anime/new?title=Frieren&storage_id=5&storage_path=Frieren.mkv');

        $this->createController(twig: $twig)->new($request);
    }

    public function testCreatePersistsAnimeOfTheChosenTypeAndRedirectsToShow(): void
    {
        $persistedAnime = null;
        $entityManager = $this->entityManagerAssigningId(1, $persistedAnime);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('anime_show', $this->callback(static fn (array $params): bool => \array_key_exists('id', $params)))
            ->willReturn('/anime/1');

        $controller = $this->createController(entityManager: $entityManager, urlGenerator: $urlGenerator);
        $request = Request::create('/anime/new', 'POST', [
            'title' => 'Frieren',
            'type' => 'tv',
            'watch_status' => 'plan',
            '_token' => 'token',
        ]);

        $response = $controller->create($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/anime/1', $response->getTargetUrl());
    }

    public function testCreateLinksStorageWhenStorageIdAndPathArePresent(): void
    {
        $storage = new Storage('Local', \sys_get_temp_dir(), StorageType::Folder);

        $persistedAnime = null;
        $entityManager = $this->entityManagerAssigningId(1, $persistedAnime);
        $entityManager->method('find')->with(Storage::class, '3')->willReturn($storage);

        $controller = $this->createController(entityManager: $entityManager);
        $request = Request::create('/anime/new', 'POST', [
            'title' => 'Frieren',
            'type' => 'tv',
            'watch_status' => 'plan',
            'storage_id' => '3',
            'storage_path' => 'Frieren.mkv',
            '_token' => 'token',
        ]);

        $controller->create($request);

        $this->assertNotNull($persistedAnime);
        $this->assertSame($storage, $persistedAnime->getStorage());
        $this->assertSame('Frieren.mkv', $persistedAnime->getStoragePath());
    }

    public function testCreateDispatchesAnimeFilesChangedEventWithCreatedReasonAfterFlush(): void
    {
        $persistedAnime = null;
        $entityManager = $this->entityManagerAssigningId(7, $persistedAnime);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (AnimeFilesChangedEvent $event): bool {
                $this->assertSame(7, $event->anime->value);
                $this->assertSame(FilesChangeReason::Created, $event->reason);

                return true;
            }));

        $controller = $this->createController(entityManager: $entityManager, eventDispatcher: $eventDispatcher);
        $request = Request::create('/anime/new', 'POST', [
            'title' => 'Frieren',
            'type' => 'tv',
            'watch_status' => 'plan',
            '_token' => 'token',
        ]);

        $controller->create($request);
    }

    public function testCreateWithEmptyTitleDoesNotPersistAndReRendersFormWithError(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');
        $entityManager->expects($this->never())->method('flush');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'anime_new.error_invalid',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig);
        $request = Request::create('/anime/new', 'POST', [
            'title' => '   ',
            'type' => 'tv',
            'watch_status' => 'plan',
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
            ->with('anime/new.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'anime_new.error_invalid',
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(entityManager: $entityManager, twig: $twig);
        $request = Request::create('/anime/new', 'POST', [
            'title' => 'Frieren',
            'type' => 'bogus',
            'watch_status' => 'plan',
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
        $request = Request::create('/anime/new', 'POST', [
            'title' => 'Frieren',
            'type' => 'tv',
            'watch_status' => 'plan',
            '_token' => 'bad',
        ]);

        $this->expectException(BadRequestHttpException::class);
        $controller->create($request);
    }
}
