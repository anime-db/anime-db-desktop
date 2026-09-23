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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

final class AnimeDetailTemplateRenderingTest extends KernelTestCase
{
    /** @return array<string, mixed> */
    private function fullyPopulatedAnime(): array
    {
        return [
            'id' => 1,
            'title' => 'Shingeki no Kyojin',
            'summary' => 'Humanity fights for survival against man-eating Titans.',
            'type' => 'tv',
            'production_status' => 'ongoing',
            'watch_status' => 'watching',
            'user_rating' => null,
            'is_series' => true,
            'episodes_count' => 25,
            'watched_episodes' => 5,
            'duration_minutes' => 24,
            'studios' => [['id' => 7, 'name' => 'MAPPA']],
            'countries' => ['JP'],
            'storage' => ['name' => 'Local', 'type' => 'folder', 'path' => '/anime/aot', 'path_available' => true],
            'names' => [['name' => '進撃の巨人', 'locale' => 'ja', 'role' => 'official']],
            'genres' => ['action', 'drama'],
            'themes' => ['military'],
            'demographic' => 'shounen',
            'notes' => 'Rewatch before the finale.',
            'labels' => [['id' => 3, 'name' => 'favorite']],
            'sources' => [['url' => 'https://shikimori.one/animes/16498', 'domain' => 'shikimori.one']],
            'cover' => 'cover_1720273812345.webp',
            'images' => ['screenshot_1720273812345.webp'],
        ];
    }

    /** @return array<string, mixed> */
    private function minimalAnime(): array
    {
        return [
            'id' => 2,
            'title' => 'A Silent Voice',
            'summary' => '',
            'type' => 'movie',
            'production_status' => 'released',
            'watch_status' => 'plan',
            'user_rating' => null,
            'is_series' => false,
            'episodes_count' => null,
            'watched_episodes' => null,
            'duration_minutes' => null,
            'studios' => [],
            'countries' => [],
            'storage' => null,
            'names' => [],
            'genres' => [],
            'themes' => [],
            'demographic' => null,
            'notes' => null,
            'labels' => [],
            'sources' => [],
            'cover' => null,
            'images' => [],
        ];
    }

    /** @return array<string, list<array{id: string, name: string}>> */
    private function emptyFillableFields(): array
    {
        return array_fill_keys(
            ['alternativeNames', 'genres', 'themes', 'demographic', 'studios', 'durationMinutes', 'episodesCount', 'countries', 'cover', 'images'],
            [],
        );
    }

    /**
     * csrf_token() (used by the always-visible episode-increment form and by the labels
     * editor) reads/writes the CSRF token through the session of the current request, so
     * rendering the editable fragment outside a real HTTP request-response cycle needs one
     * pushed onto the request stack manually (see SettingsTemplateRenderingTest for the same
     * pattern).
     */
    private function pushRequestWithSession(string $uri): void
    {
        $request = Request::create($uri);
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    public function testShowRendersFullyPopulatedAnimeWithoutErrors(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1');

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields()]);

        $this->assertStringContainsString('Shingeki no Kyojin', $html);
        $this->assertStringContainsString('Humanity fights for survival against man-eating Titans.', $html);
        $this->assertStringContainsString('ТВ-сериал', $html);
        $this->assertStringContainsString('Онгоинг', $html);
        $this->assertStringContainsString('25', $html);
        $this->assertStringContainsString('24', $html);
        $this->assertStringContainsString('MAPPA', $html);
        $this->assertStringContainsString('JP', $html);
        $this->assertStringContainsString('/anime/aot', $html);
        $this->assertStringContainsString('進撃の巨人', $html);
        $this->assertStringContainsString('Экшен', $html);
        $this->assertStringContainsString('Военный', $html);
        $this->assertStringContainsString('Сёнэн', $html);
        $this->assertStringContainsString('Rewatch before the finale.', $html);
        $this->assertStringContainsString('anime-detail__status-badge--ongoing', $html);
        $this->assertStringContainsString('favorite', $html);
        $this->assertStringContainsString('/?labels=3', $html);
        $this->assertStringContainsString('data-open-folder-path="/anime/aot"', $html);
        $this->assertStringNotContainsString('disabled', $html);
        $this->assertStringContainsString('https://shikimori.one/favicon.ico', $html);
        $this->assertStringContainsString('https://shikimori.one/animes/16498', $html);
        $this->assertStringContainsString('app-media://anime/1/cover_1720273812345.webp', $html);
        $this->assertStringContainsString('app-media://anime/1/screenshot_1720273812345.webp', $html);
    }

    /**
     * Issue #720: anime/show.html.twig now delegates its widget slots to the same
     * plugin/_widget_slots.html.twig partial anime/list.html.twig uses for catalog widgets — this
     * pins that the entry-widget side of that partial still carries `entryId` (see
     * WidgetSlotsTemplateRenderingTest for the partial's own coverage of both branches, and
     * AnimeListTemplateRenderingTest for the catalog-widget side omitting it).
     */
    public function testShowRendersAWidgetSlotWithTheAnimeIdAsEntryId(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1');

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', [
            'anime' => $this->fullyPopulatedAnime(),
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'related']],
            'plugins_ui' => [],
            'fillable_fields' => $this->emptyFillableFields(),
        ]);

        $this->assertStringContainsString('hx-get="/plugin/animedb-shikimori/widget/related?entryId=1"', $html);
    }

    /**
     * Genre, theme, studio and type are links into the catalog with a single filter value
     * applied (issue #714) — the same pattern the label links already use (issue #104), which
     * this test does not touch.
     */
    public function testShowRendersGenreThemeStudioAndTypeAsCatalogFilterLinks(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1');

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields()]);

        $this->assertStringContainsString('href="/?type[]=tv"', $html);
        $this->assertStringContainsString('href="/?genres[]=action"', $html);
        $this->assertStringContainsString('href="/?genres[]=drama"', $html);
        $this->assertStringContainsString('href="/?themes[]=military"', $html);
        $this->assertStringContainsString('href="/?studios[]=7"', $html);
    }

    /**
     * The catalog link (issue #719) must always be present, even with no history to go back to -
     * anime-detail.js decides at runtime whether a click goes back through history or follows this
     * href, so the href itself must stay a working plain link to the catalog root.
     */
    public function testShowRendersCatalogBackLink(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1');

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields()]);

        $this->assertStringContainsString('data-catalog-back-link', $html);
        $this->assertStringContainsString('href="/"', $html);
        $this->assertStringContainsString('← Каталог', $html);
    }

    public function testShowRendersAnimeWithoutOptionalFieldsWithoutErrors(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/2');

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->minimalAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields()]);

        $this->assertStringContainsString('A Silent Voice', $html);
        $this->assertStringContainsString('Фильм', $html);
        $this->assertStringContainsString('Вышло', $html);
        $this->assertStringNotContainsString('anime_detail.field_episodes_count', $html);
        $this->assertStringNotContainsString('data-open-folder-path', $html);
        $this->assertStringNotContainsString('anime-detail__sources', $html);
        $this->assertStringContainsString('anime-detail__cover--placeholder', $html);

        // The gallery container itself must always be in the DOM, with a stable id, even with
        // no images at all - an out-of-band swap after the first successful fill has nowhere to
        // land otherwise (issue #507).
        $this->assertStringContainsString('id="anime-gallery-2"', $html);
        $this->assertStringContainsString('anime-detail__gallery-empty', $html);
        $this->assertStringNotContainsString('anime-detail__gallery-list', $html);
    }

    public function testShowRendersDisabledOpenFolderButtonWhenStoragePathIsUnavailable(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/3');

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $anime = $this->fullyPopulatedAnime();
        $anime['storage']['path_available'] = false;

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', ['anime' => $anime, 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields()]);

        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('Путь не доступен', $html);
    }

    /**
     * Both the cover and the gallery sections get their own "fill from source" button once a
     * plugin actively supports the field (issue #507) - same button/dropdown macro
     * anime/_fill_fields.html.twig already uses for the other reference fields, and the same
     * fillable_fields source (FillableFieldsPresenter::build()), not a second one.
     */
    public function testShowRendersFillButtonsForCoverAndImagesWhenAPluginSupportsThem(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1');

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        $fillableFields = $this->emptyFillableFields();
        $fillableFields['cover'] = [['id' => 'animedb-shikimori', 'name' => 'Shikimori']];
        $fillableFields['images'] = [['id' => 'animedb-shikimori', 'name' => 'Shikimori']];

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $fillableFields]);

        $this->assertStringContainsString('id="anime-media-1"', $html);
        $this->assertStringContainsString('id="anime-gallery-1"', $html);
        // show.html.twig includes both partials directly for the initial full-page load, not as
        // an out-of-band swap - hx-swap-oob is only emitted when AnimeFillController renders
        // them with oob = true (issue #507 review feedback).
        $this->assertStringNotContainsString('hx-swap-oob', $html);
        $this->assertStringContainsString('hx-post="/anime/1/fill/cover"', $html);
        $this->assertStringContainsString('hx-post="/anime/1/fill/images"', $html);
        $this->assertStringContainsString('name="plugin_id" value="animedb-shikimori"', $html);
    }
}
