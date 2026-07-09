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
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Entity\TvAnime;
use App\Service\AnimeViewFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

final class AnimeControllerTest extends TestCase
{
    public function testShowPassesFullyPopulatedReferenceFieldsToTemplate(): void
    {
        $studio = new Studio();
        $studio->rename('MAPPA');

        $storage = new Storage();
        $storage->setName('Local')->setType(StorageType::Folder)->setPath(sys_get_temp_dir());

        $label = new Label('favorite');

        $anime = new TvAnime();
        $anime->setTitle('Shingeki no Kyojin')
            ->setDurationMinutes(24)
            ->setNotes('Rewatch before the finale.')
            ->setCountries(['JP'])
            ->setStorage($storage)
            ->addStudio($studio)
            ->addGenre(GenreCode::Action)
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

                return 'Shingeki no Kyojin' === $view['title']
                    && 'tv' === $view['type']
                    && true === $view['is_series']
                    && 'watching' === $view['watch_status']
                    && null === $view['user_rating']
                    && 25 === $view['episodes_count']
                    && null === $view['watched_episodes']
                    && 24 === $view['duration_minutes']
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
                    && 'Rewatch before the finale.' === $view['notes']
                    && [['id' => null, 'name' => 'favorite']] === $view['labels']
                    && [['url' => 'https://shikimori.one/animes/16498', 'domain' => 'shikimori.one']] === $view['sources']
                    && 'cover_1720273812345.webp' === $view['cover']
                    && ['screenshot_1720273812345.webp'] === $view['images'];
            }))
            ->willReturn('<html></html>');

        $controller = new AnimeController($twig, new AnimeViewFactory());
        $response = $controller->show($anime);

        $this->assertSame(200, $response->getStatusCode());
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

                return false === $view['is_series']
                    && 'plan' === $view['watch_status']
                    && null === $view['user_rating']
                    && null === $view['episodes_count']
                    && null === $view['watched_episodes']
                    && null === $view['storage']
                    && [] === $view['studios']
                    && [] === $view['countries']
                    && [] === $view['names']
                    && [] === $view['genres']
                    && null === $view['notes']
                    && [] === $view['labels']
                    && [] === $view['sources']
                    && null === $view['cover']
                    && [] === $view['images'];
            }))
            ->willReturn('<html></html>');

        $controller = new AnimeController($twig, new AnimeViewFactory());
        $controller->show($anime);
    }
}
