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
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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
        return new EntryWidgetRegistry([], new PluginsConfigStore(''));
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
                    && ['MAPPA'] === $view['studios']
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

        $controller = new AnimeController($twig, $this->createViewFactory(), $this->createEntryWidgetRegistry());
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

        $controller = new AnimeController($twig, $this->createViewFactory(), $this->createEntryWidgetRegistry());
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

        $controller = new AnimeController($twig, $this->createViewFactory(), $this->createEntryWidgetRegistry());
        $controller->show($anime);
    }
}
