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

use App\Controller\HomeController;
use App\Repository\AnimeRepository;
use App\Repository\StorageRepository;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

final class HomeControllerTest extends TestCase
{
    private function createController(
        bool $hasStorage,
        bool $hasAnime,
        Environment $twig,
    ): HomeController {
        $storages = $this->createStub(StorageRepository::class);
        $storages->method('hasAny')->willReturn($hasStorage);

        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('hasAny')->willReturn($hasAnime);

        return new HomeController($twig, $storages, $animeRepository);
    }

    public function testIndexShowsOnboardingBannerWhenCatalogIsEmpty(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', ['showOnboarding' => true])
            ->willReturn('<html></html>');

        $controller = $this->createController(hasStorage: false, hasAnime: false, twig: $twig);
        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexHidesOnboardingBannerWhenStorageExists(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', ['showOnboarding' => false])
            ->willReturn('<html></html>');

        $controller = $this->createController(hasStorage: true, hasAnime: false, twig: $twig);
        $controller->index();
    }

    public function testIndexHidesOnboardingBannerWhenAnimeExists(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', ['showOnboarding' => false])
            ->willReturn('<html></html>');

        $controller = $this->createController(hasStorage: false, hasAnime: true, twig: $twig);
        $controller->index();
    }
}
