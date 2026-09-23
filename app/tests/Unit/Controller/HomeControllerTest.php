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

use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use App\Controller\HomeController;
use App\Repository\AnimeRepository;
use App\Repository\StorageRepository;
use App\Service\Plugin\CatalogWidgetRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class HomeControllerTest extends TestCase
{
    private function createEmptyCatalogWidgets(): CatalogWidgetRegistry
    {
        return new CatalogWidgetRegistry([], new PluginsConfigStore(''), $this->createStub(TranslatorInterface::class));
    }

    private function createController(
        bool $hasStorage,
        bool $hasAnime,
        Environment $twig,
        ?CatalogWidgetRegistry $catalogWidgets = null,
    ): HomeController {
        $storages = $this->createStub(StorageRepository::class);
        $storages->method('hasAny')->willReturn($hasStorage);

        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('hasAny')->willReturn($hasAnime);

        return new HomeController($twig, $storages, $animeRepository, $catalogWidgets ?? $this->createEmptyCatalogWidgets());
    }

    public function testIndexShowsOnboardingBannerWhenCatalogIsEmpty(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', ['showOnboarding' => true, 'widgets' => []])
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
            ->with('anime/list.html.twig', ['showOnboarding' => false, 'widgets' => []])
            ->willReturn('<html></html>');

        $controller = $this->createController(hasStorage: true, hasAnime: false, twig: $twig);
        $controller->index();
    }

    public function testIndexHidesOnboardingBannerWhenAnimeExists(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', ['showOnboarding' => false, 'widgets' => []])
            ->willReturn('<html></html>');

        $controller = $this->createController(hasStorage: false, hasAnime: true, twig: $twig);
        $controller->index();
    }

    /**
     * Core of issue #720: an enabled catalog widget must reach the template at all, an
     * enabled-then-disabled (or never-enabled) one must not — this is what
     * {@see CatalogWidgetRegistry::findAllActive()} already guarantees, but nothing wired it
     * into this controller before.
     */
    public function testIndexPassesOnlyActiveCatalogWidgetsToTheTemplate(): void
    {
        $pluginsDir = sys_get_temp_dir().'/home-controller-catalog-widgets-test-'.uniqid();
        mkdir($pluginsDir, recursive: true);

        $pluginsConfigStore = new PluginsConfigStore($pluginsDir.'/plugins.json');
        file_put_contents($pluginsDir.'/plugins.json', (string) json_encode([
            'animedb-shikimori' => ['features' => ['spotlight' => true]],
            'animedb-anilist' => ['features' => ['spotlight' => false]],
        ]));

        $catalogWidgets = new CatalogWidgetRegistry(
            [
                'animedb-shikimori:spotlight' => $this->createStub(CatalogWidgetInterface::class),
                'animedb-anilist:spotlight' => $this->createStub(CatalogWidgetInterface::class),
            ],
            $pluginsConfigStore,
            $this->createStub(TranslatorInterface::class),
        );

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', [
                'showOnboarding' => false,
                'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'spotlight']],
            ])
            ->willReturn('<html></html>');

        try {
            $controller = $this->createController(hasStorage: true, hasAnime: false, twig: $twig, catalogWidgets: $catalogWidgets);
            $controller->index();
        } finally {
            unlink($pluginsDir.'/plugins.json');
            rmdir($pluginsDir);
        }
    }
}
