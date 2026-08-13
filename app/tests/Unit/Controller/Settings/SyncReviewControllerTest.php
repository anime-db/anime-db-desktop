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

namespace App\Tests\Unit\Controller\Settings;

use App\Controller\Settings\SyncReviewController;
use App\Entity\Anime;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\SyncReviewItem;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Repository\SyncReviewItemRepository;
use App\Service\Sync\SyncReviewService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class SyncReviewControllerTest extends TestCase
{
    private function createController(
        ?SyncReviewItemRepository $syncReviewItemRepository = null,
        ?AnimeRepository $animeRepository = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
        ?Environment $twig = null,
    ): SyncReviewController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/settings/sync-review');
        }

        return new SyncReviewController(
            new SyncReviewService($syncReviewItemRepository ?? $this->createStub(SyncReviewItemRepository::class)),
            $animeRepository ?? $this->createStub(AnimeRepository::class),
            $csrfTokenManager,
            $urlGenerator,
            $twig ?? $this->createStub(Environment::class),
        );
    }

    public function testIndexPassesUnresolvedItemsAndDuplicateClustersToTemplate(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 10);

        $anime1 = new TvAnime();
        $anime1->setTitle('First');
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime1, 1);
        $anime2 = new TvAnime();
        $anime2->setTitle('Second');
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime2, 2);

        $syncReviewItemRepository = $this->createStub(SyncReviewItemRepository::class);
        $syncReviewItemRepository->method('findAllUnresolvedOrderedByCreatedAt')->willReturn([$item]);

        $animeRepository = $this->createMock(AnimeRepository::class);
        $animeRepository->expects($this->once())->method('findByIds')->with([1, 2])->willReturn([1 => $anime1, 2 => $anime2]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/sync_review/index.html.twig', $this->callback(
                static fn (array $params): bool => [$item] === $params['items']
                    && [10 => [$anime1, $anime2]] === $params['duplicateClusters'],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(syncReviewItemRepository: $syncReviewItemRepository, animeRepository: $animeRepository, twig: $twig);
        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexPassesDeletionDetailsToTemplate(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::DeletionConflict, [
            'anime_id' => 7,
            'deleted_from' => 'animedb-shikimori',
            'still_present_on' => ['animedb-mal'],
        ]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 20);

        $anime = new TvAnime();
        $anime->setTitle('Trigun');
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime, 7);

        $syncReviewItemRepository = $this->createStub(SyncReviewItemRepository::class);
        $syncReviewItemRepository->method('findAllUnresolvedOrderedByCreatedAt')->willReturn([$item]);

        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturnCallback(
            static fn (array $ids): array => $ids === [7] ? [7 => $anime] : [],
        );

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/sync_review/index.html.twig', $this->callback(
                static fn (array $params): bool => [$item] === $params['items']
                    && [20 => []] === $params['duplicateClusters']
                    && [20 => ['anime' => $anime, 'deletedFrom' => 'animedb-shikimori', 'stillPresentOn' => ['animedb-mal']]] === $params['deletionDetails'],
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(syncReviewItemRepository: $syncReviewItemRepository, animeRepository: $animeRepository, twig: $twig);

        $this->assertSame(200, $controller->index()->getStatusCode());
    }

    public function testResolveMarksItemResolvedAndRedirectsToIndex(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 5);

        $syncReviewItemRepository = $this->createMock(SyncReviewItemRepository::class);
        $syncReviewItemRepository->expects($this->once())->method('save')->with($item);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())
            ->method('generate')
            ->with('settings_sync_review_index')
            ->willReturn('/settings/sync-review');

        $controller = $this->createController(syncReviewItemRepository: $syncReviewItemRepository, urlGenerator: $router);
        $request = Request::create('/settings/sync-review/5/resolve', 'POST', ['_token' => 'token']);

        $response = $controller->resolve($item, $request);

        $this->assertTrue($item->isResolved());
        $this->assertSame('/settings/sync-review', $response->getTargetUrl());
    }

    public function testResolveRejectsInvalidCsrfToken(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);
        (new \ReflectionProperty(SyncReviewItem::class, 'id'))->setValue($item, 5);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $syncReviewItemRepository = $this->createMock(SyncReviewItemRepository::class);
        $syncReviewItemRepository->expects($this->never())->method('save');

        $controller = $this->createController(syncReviewItemRepository: $syncReviewItemRepository, csrfTokenManager: $csrf);
        $request = Request::create('/settings/sync-review/5/resolve', 'POST', ['_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->resolve($item, $request);
    }
}
