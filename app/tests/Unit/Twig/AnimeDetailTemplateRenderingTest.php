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

namespace App\Tests\Unit\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

final class AnimeDetailTemplateRenderingTest extends KernelTestCase
{
    /** @return array<string, mixed> */
    private function fullyPopulatedAnime(): array
    {
        return [
            'title' => 'Shingeki no Kyojin',
            'type' => 'tv',
            'production_status' => 'ongoing',
            'episodes_count' => 25,
            'duration_minutes' => 24,
            'studios' => ['MAPPA'],
            'countries' => ['JP'],
            'storage' => ['name' => 'Local', 'type' => 'folder', 'path' => '/anime/aot'],
            'names' => [['name' => '進撃の巨人', 'type' => 'original']],
            'genres' => ['action', 'drama'],
            'notes' => 'Rewatch before the finale.',
        ];
    }

    /** @return array<string, mixed> */
    private function minimalAnime(): array
    {
        return [
            'title' => 'A Silent Voice',
            'type' => 'movie',
            'production_status' => 'released',
            'episodes_count' => null,
            'duration_minutes' => null,
            'studios' => [],
            'countries' => [],
            'storage' => null,
            'names' => [],
            'genres' => [],
            'notes' => null,
        ];
    }

    public function testShowRendersFullyPopulatedAnimeWithoutErrors(): void
    {
        self::bootKernel();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push(Request::create('/anime/1'));

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime()]);

        $this->assertStringContainsString('Shingeki no Kyojin', $html);
        $this->assertStringContainsString('ТВ-сериал', $html);
        $this->assertStringContainsString('Онгоинг', $html);
        $this->assertStringContainsString('25', $html);
        $this->assertStringContainsString('24', $html);
        $this->assertStringContainsString('MAPPA', $html);
        $this->assertStringContainsString('JP', $html);
        $this->assertStringContainsString('/anime/aot', $html);
        $this->assertStringContainsString('進撃の巨人', $html);
        $this->assertStringContainsString('Экшен', $html);
        $this->assertStringContainsString('Rewatch before the finale.', $html);
        $this->assertStringContainsString('anime-detail__status-badge--ongoing', $html);
    }

    public function testShowRendersAnimeWithoutOptionalFieldsWithoutErrors(): void
    {
        self::bootKernel();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push(Request::create('/anime/2'));

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->minimalAnime()]);

        $this->assertStringContainsString('A Silent Voice', $html);
        $this->assertStringContainsString('Фильм', $html);
        $this->assertStringContainsString('Вышло', $html);
        $this->assertStringNotContainsString('anime_detail.field_episodes_count', $html);
    }
}
