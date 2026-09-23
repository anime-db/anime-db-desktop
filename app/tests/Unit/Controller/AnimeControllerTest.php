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

use App\Controller\AnimeController;
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Entity\TvAnime;
use App\Service\AnimeViewFactory;
use App\Service\Plugin\EntryWidgetRegistry;
use App\Service\Plugin\Filler\FillableFieldsPresenter;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginAssetResolver;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PluginUiAssetsResolver;
use App\Tests\Fixtures\Plugin\Widget\FakeEntryWidget;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AnimeControllerTest extends TestCase
{
    private function createViewFactory(): AnimeViewFactory
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new AnimeViewFactory($requestStack);
    }

    private function createEntryWidgetRegistry(): EntryWidgetRegistry
    {
        return new EntryWidgetRegistry([], new PluginsConfigStore(''), $this->createStub(TranslatorInterface::class));
    }

    private function createFillableFieldsPresenter(): FillableFieldsPresenter
    {
        return new FillableFieldsPresenter(
            new FillerRegistry([], new PluginsConfigStore('')),
            new InstalledPluginsRegistry(sys_get_temp_dir(), new PluginsConfigStore(''), new NullLogger()),
        );
    }

    private function createController(Environment $twig): AnimeController
    {
        return new AnimeController(
            $twig,
            $this->createViewFactory(),
            $this->createEntryWidgetRegistry(),
            $this->createFillableFieldsPresenter(),
            $this->createPluginUiAssetsResolver(
                new InstalledPluginsRegistry(sys_get_temp_dir(), new PluginsConfigStore(''), new NullLogger()),
            ),
        );
    }

    private function createPluginUiAssetsResolver(InstalledPluginsRegistry $installedPlugins): PluginUiAssetsResolver
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $params): string => \sprintf(
                '/plugin/%s/asset/%s/%s',
                $params['pluginId'],
                $params['fingerprint'],
                $params['path'],
            ),
        );

        return new PluginUiAssetsResolver(new PluginAssetResolver($installedPlugins), $urlGenerator, new NullLogger());
    }

    public function testShowPassesFullyPopulatedReferenceFieldsToTemplate(): void
    {
        $studio = new Studio();
        $studio->rename('MAPPA');

        $storage = new Storage('Local', sys_get_temp_dir(), StorageType::Folder);

        $label = new Label('favorite');

        $anime = new TvAnime();
        $anime->setTitle('Shingeki no Kyojin')
            ->setDurationMinutes(24)
            ->setNotes('Rewatch before the finale.')
            ->setCountries(['JP'])
            ->setStorage($storage)
            ->addStudio($studio)
            ->addGenre(GenreCode::Action)
            ->addTheme(ThemeCode::Military)
            ->setDemographic(Demographic::Shounen)
            ->addName('進撃の巨人', AnimeNameType::Original)
            ->addLabel($label)
            ->addSource('https://shikimori.one/animes/16498')
            ->addImage('screenshot_1720273812345.webp')
            ->setCover('cover_1720273812345.webp')
            ->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(25);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/show.html.twig', $this->callback(function (array $params) use ($storage) {
                $view = $params['anime'];

                return $view['title'] === 'Shingeki no Kyojin'
                    && $view['type'] === 'tv'
                    && $view['is_series'] === true
                    && $view['watch_status'] === 'watching'
                    && $view['user_rating'] === null
                    && $view['episodes_count'] === 25
                    && $view['watched_episodes'] === null
                    && $view['duration_minutes'] === 24
                    && [['id' => null, 'name' => 'MAPPA']] === $view['studios']
                    && ['JP'] === $view['countries']
                    && [
                        'name' => 'Local',
                        'type' => 'folder',
                        'path' => $storage->getPath(),
                        'path_available' => true,
                    ] === $view['storage']
                    && [['name' => '進撃の巨人', 'type' => 'original']] === $view['names']
                    && ['action'] === $view['genres']
                    && ['military'] === $view['themes']
                    && $view['demographic'] === 'shounen'
                    && $view['notes'] === 'Rewatch before the finale.'
                    && [['id' => null, 'name' => 'favorite']] === $view['labels']
                    && [['url' => 'https://shikimori.one/animes/16498', 'domain' => 'shikimori.one']] === $view['sources']
                    && $view['cover'] === 'cover_1720273812345.webp'
                    && ['screenshot_1720273812345.webp'] === $view['images'];
            }))
            ->willReturn('<html></html>');

        $controller = $this->createController($twig);
        $response = $controller->show($anime);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShowComposesStoragePathFromStorageRootAndAnimeStoragePath(): void
    {
        $storageRoot = sys_get_temp_dir();
        $storage = new Storage('Local', $storageRoot, StorageType::Folder);

        $anime = new MovieAnime();
        $anime->setTitle('A Silent Voice')
            ->setWatchStatus(WatchStatus::Plan)
            ->setStorage($storage)
            ->setStoragePath('A Silent Voice.mkv');

        $expectedPath = rtrim($storageRoot, '\\/').\DIRECTORY_SEPARATOR.'A Silent Voice.mkv';

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/show.html.twig', $this->callback(static function (array $params) use ($expectedPath) {
                $view = $params['anime'];

                // The composed path points at a nonexistent file, so path_available must be false —
                // this is what makes the "open folder" button disabled for it, not for the storage root.
                return $expectedPath === $view['storage']['path']
                    && $view['storage']['path_available'] === false;
            }))
            ->willReturn('<html></html>');

        $controller = $this->createController($twig);
        $controller->show($anime);
    }

    public function testShowOmitsEpisodesCountAndStorageForAMovieWithoutThem(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('A Silent Voice')->setWatchStatus(WatchStatus::Plan);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/show.html.twig', $this->callback(static function (array $params) {
                $view = $params['anime'];

                return $view['is_series'] === false
                    && $view['watch_status'] === 'plan'
                    && $view['user_rating'] === null
                    && $view['episodes_count'] === null
                    && $view['watched_episodes'] === null
                    && $view['storage'] === null
                    && $view['studios'] === []
                    && $view['countries'] === []
                    && $view['names'] === []
                    && $view['genres'] === []
                    && $view['themes'] === []
                    && $view['demographic'] === null
                    && $view['notes'] === null
                    && $view['labels'] === []
                    && $view['sources'] === []
                    && $view['cover'] === null
                    && $view['images'] === [];
            }))
            ->willReturn('<html></html>');

        $controller = $this->createController($twig);
        $controller->show($anime);
    }

    public function testShowIncludesPluginUiOnlyForAPluginWithBothAnActiveWidgetAndADeclaredUi(): void
    {
        $pluginsDir = sys_get_temp_dir().'/anime-anime-controller-ui-test-'.uniqid();
        mkdir($pluginsDir.'/animedb-shikimori/assets', recursive: true);
        mkdir($pluginsDir.'/animedb-anilist/assets', recursive: true);

        file_put_contents($pluginsDir.'/animedb-shikimori/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
            'ui' => ['css' => ['assets/carousel.css'], 'js' => []],
        ]));
        file_put_contents($pluginsDir.'/animedb-shikimori/assets/carousel.css', '.carousel {}');
        // Has an active widget too, but declares no "ui" — must not appear in plugins_ui.
        file_put_contents($pluginsDir.'/animedb-anilist/manifest.json', (string) json_encode([
            'id' => 'animedb-anilist',
            'name' => 'AniList',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $pluginsConfigStore = new PluginsConfigStore($pluginsDir.'/plugins.json');
        file_put_contents($pluginsDir.'/plugins.json', (string) json_encode([
            'animedb-shikimori' => ['features' => ['related' => true]],
            'animedb-anilist' => ['features' => ['related' => true]],
        ]));

        $installedPlugins = new InstalledPluginsRegistry($pluginsDir, $pluginsConfigStore, new NullLogger());
        $installedPlugins->reconcile();

        $entryWidgets = new EntryWidgetRegistry(
            [
                'animedb-shikimori:related' => new FakeEntryWidget(),
                'animedb-anilist:related' => new FakeEntryWidget(),
            ],
            $pluginsConfigStore,
            $this->createStub(TranslatorInterface::class),
        );

        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/show.html.twig', $this->callback(static function (array $params): bool {
                if (\count($params['plugins_ui']) !== 1) {
                    return false;
                }

                return $params['plugins_ui'][0]['pluginId'] === 'animedb-shikimori'
                    && \count($params['plugins_ui'][0]['css']) === 1
                    && str_ends_with($params['plugins_ui'][0]['css'][0], '/assets/carousel.css')
                    && $params['plugins_ui'][0]['js'] === [];
            }))
            ->willReturn('<html></html>');

        try {
            $controller = new AnimeController(
                $twig,
                $this->createViewFactory(),
                $entryWidgets,
                $this->createFillableFieldsPresenter(),
                $this->createPluginUiAssetsResolver($installedPlugins),
            );
            $response = $controller->show($anime);

            $this->assertSame(200, $response->getStatusCode());
        } finally {
            $this->removeDirectory($pluginsDir);
        }
    }

    public function testShowRendersNormallyWhenADeclaredUiAssetFileIsMissing(): void
    {
        $pluginsDir = sys_get_temp_dir().'/anime-anime-controller-missing-ui-test-'.uniqid();
        mkdir($pluginsDir.'/animedb-shikimori/assets', recursive: true);

        file_put_contents($pluginsDir.'/animedb-shikimori/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
            'ui' => ['css' => ['assets/missing.css'], 'js' => []],
        ]));

        $pluginsConfigStore = new PluginsConfigStore($pluginsDir.'/plugins.json');
        file_put_contents($pluginsDir.'/plugins.json', (string) json_encode([
            'animedb-shikimori' => ['features' => ['related' => true]],
        ]));

        $installedPlugins = new InstalledPluginsRegistry($pluginsDir, $pluginsConfigStore, new NullLogger());
        $installedPlugins->reconcile();

        $entryWidgets = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => new FakeEntryWidget()],
            $pluginsConfigStore,
            $this->createStub(TranslatorInterface::class),
        );

        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/show.html.twig', $this->callback(static fn (array $params): bool => $params['plugins_ui'] === []))
            ->willReturn('<html></html>');

        try {
            $controller = new AnimeController(
                $twig,
                $this->createViewFactory(),
                $entryWidgets,
                $this->createFillableFieldsPresenter(),
                $this->createPluginUiAssetsResolver($installedPlugins),
            );
            $response = $controller->show($anime);

            $this->assertSame(200, $response->getStatusCode());
        } finally {
            $this->removeDirectory($pluginsDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
