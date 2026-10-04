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

namespace App\Tests\Unit\Twig;

use App\Entity\Anime;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Storage;
use App\Entity\TvAnime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Real-Twig render coverage for downloads/new.html.twig (issue #855) — the controller's own unit
 * tests mock Environment::render() entirely, so nothing else exercises this file's actual markup.
 */
final class DownloadNewTemplateRenderingTest extends KernelTestCase
{
    private function pushRequestWithSession(): void
    {
        $request = Request::create('/downloads/new');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    private function makeStorage(int $id): Storage
    {
        $storage = new Storage('AnimeDB', sys_get_temp_dir(), StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);

        return $storage;
    }

    private function makeAnime(int $id): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Shingeki no Kyojin')->setWatchStatus(WatchStatus::Plan);
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime, $id);

        return $anime;
    }

    public function testRendersEmptyFormWithoutErrors(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('downloads/new.html.twig', [
            'selectedAnime' => null,
            'magnet' => '',
            'storages' => [$this->makeStorage(1)],
            'selectedStorageId' => 1,
            'error' => null,
            'errorParams' => [],
            'info' => null,
            'occupyingAnimeId' => null,
        ]);

        $this->assertStringContainsString('download-new-anime-search', $html);
        $this->assertStringContainsString('download-new-file', $html);
        $this->assertStringContainsString('download-new-magnet', $html);
    }

    public function testRendersPreselectedAnimeAndErrorWithLinkToOccupyingAnime(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        $anime = $this->makeAnime(5);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('downloads/new.html.twig', [
            'selectedAnime' => $anime,
            'magnet' => 'magnet:?xt=urn:btih:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'storages' => [$this->makeStorage(1)],
            'selectedStorageId' => 1,
            'error' => 'download_new.error_already_linked',
            'errorParams' => ['%id%' => 42],
            'info' => null,
            'occupyingAnimeId' => 42,
        ]);

        $this->assertStringContainsString('Shingeki no Kyojin', $html);
        $this->assertStringContainsString('/anime/42', $html);
    }
}
