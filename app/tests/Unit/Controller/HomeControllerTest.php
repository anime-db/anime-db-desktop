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
use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Repository\AnimeRepository;
use App\Repository\StorageRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\CatalogWidgetRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Fixtures\Plugin\Widget\FakeCatalogWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class HomeControllerTest extends TestCase
{
    private function createEmptyCatalogWidgets(): CatalogWidgetRegistry
    {
        return new CatalogWidgetRegistry([], new PluginsConfigStore(''), $this->createStub(TranslatorInterface::class));
    }

    /**
     * @param Storage[] $scannableStorages only read by the controller when $hasAnime is false —
     *                                     see the ternary in HomeController::index()
     */
    private function createController(
        bool $hasAnime,
        Environment $twig,
        ?CatalogWidgetRegistry $catalogWidgets = null,
        ?AppSettingsProvider $settings = null,
        array $scannableStorages = [],
    ): HomeController {
        $storages = $this->createMock(StorageRepository::class);
        if ($hasAnime) {
            // Asserts the ternary in HomeController::index() actually short-circuits: a
            // non-empty catalog must never trigger a findAllScannable() lookup.
            $storages->expects($this->never())->method('findAllScannable');
        } else {
            $storages->expects($this->once())->method('findAllScannable')->willReturn($scannableStorages);
        }

        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('hasAny')->willReturn($hasAnime);

        return new HomeController(
            $twig,
            $storages,
            $animeRepository,
            $catalogWidgets ?? $this->createEmptyCatalogWidgets(),
            $settings ?? new AppSettingsProvider(new AppConfigStore(sys_get_temp_dir().'/home-controller-settings-test-'.uniqid().'.json')),
        );
    }

    public function testIndexShowsOnboardingBannerWhenCatalogIsEmpty(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', [
                'showOnboarding' => true,
                'hasScannableStorage' => false,
                'singleScannableStorageId' => null,
                'widgets' => [],
                'collapsedFilterSections' => [],
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(hasAnime: false, twig: $twig);
        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Acceptance (issue #835): the onboarding invitation must not depend on whether a Storage is
     * configured — only on the catalog actually being empty. A configured-but-unscanned storage
     * used to hide the old banner entirely; here it must still show, and additionally must switch
     * its storage card from "add" to "scan" (hasScannableStorage: true).
     */
    public function testIndexShowsOnboardingBannerWhenScannableStorageExistsButCatalogIsEmpty(): void
    {
        $storage = new Storage('Main folder', \sys_get_temp_dir(), StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, 7);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', [
                'showOnboarding' => true,
                'hasScannableStorage' => true,
                'singleScannableStorageId' => 7,
                'widgets' => [],
                'collapsedFilterSections' => [],
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(hasAnime: false, twig: $twig, scannableStorages: [$storage]);
        $controller->index();
    }

    /**
     * Acceptance (issue #835): with two or more scannable storages, the invitation card must not
     * pick one of them to auto-scan — \count(...) === 1 in HomeController::index() must stay
     * strict so a wider condition (e.g. >= 1) would be caught here.
     */
    public function testIndexShowsOnboardingBannerWithMultipleScannableStorages(): void
    {
        $first = new Storage('Main folder', \sys_get_temp_dir(), StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($first, 7);
        $second = new Storage('Second folder', \sys_get_temp_dir(), StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($second, 8);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', [
                'showOnboarding' => true,
                'hasScannableStorage' => true,
                'singleScannableStorageId' => null,
                'widgets' => [],
                'collapsedFilterSections' => [],
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(hasAnime: false, twig: $twig, scannableStorages: [$first, $second]);
        $controller->index();
    }

    public function testIndexHidesOnboardingBannerWhenAnimeExists(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', [
                'showOnboarding' => false,
                'hasScannableStorage' => false,
                'singleScannableStorageId' => null,
                'widgets' => [],
                'collapsedFilterSections' => [],
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(hasAnime: true, twig: $twig);
        $controller->index();
    }

    /**
     * Acceptance (issue #820): the saved collapse state reaches the template unchanged, so it
     * can render aria-expanded/hidden from it on first paint instead of every section flashing
     * open first.
     */
    public function testIndexPassesSavedCollapsedFilterSectionsToTheTemplate(): void
    {
        $configPath = sys_get_temp_dir().'/home-controller-settings-test-'.uniqid().'.json';
        file_put_contents($configPath, (string) json_encode(['collapsedFilterSections' => ['genres', 'studios']]));
        $settings = new AppSettingsProvider(new AppConfigStore($configPath));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', [
                'showOnboarding' => false,
                'hasScannableStorage' => false,
                'singleScannableStorageId' => null,
                'widgets' => [],
                'collapsedFilterSections' => ['genres', 'studios'],
            ])
            ->willReturn('<html></html>');

        try {
            $controller = $this->createController(hasAnime: true, twig: $twig, settings: $settings);
            $controller->index();
        } finally {
            unlink($configPath);
        }
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

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $catalogWidgets = new CatalogWidgetRegistry(
            [
                'animedb-shikimori:spotlight' => new FakeCatalogWidget(),
                'animedb-anilist:spotlight' => $this->createStub(CatalogWidgetInterface::class),
            ],
            $pluginsConfigStore,
            $translator,
        );

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/list.html.twig', [
                'showOnboarding' => false,
                'hasScannableStorage' => false,
                'singleScannableStorageId' => null,
                'widgets' => [[
                    'pluginId' => 'animedb-shikimori',
                    'widgetName' => 'spotlight',
                    'title' => 'spotlight',
                    'pluginName' => 'animedb-shikimori',
                ]],
                'collapsedFilterSections' => [],
            ])
            ->willReturn('<html></html>');

        try {
            $controller = $this->createController(hasAnime: true, twig: $twig, catalogWidgets: $catalogWidgets);
            $controller->index();
        } finally {
            unlink($pluginsDir.'/plugins.json');
            rmdir($pluginsDir);
        }
    }
}
