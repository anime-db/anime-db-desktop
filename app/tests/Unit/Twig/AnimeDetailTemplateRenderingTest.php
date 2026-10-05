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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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
     * The `<main>` detail content on its own, excluding base.html.twig's top-nav, whose "Add"
     * menu (issue #834) can carry an unrelated "disabled" dropdown item depending on this test's
     * container-wired plugin state.
     */
    private function mainContent(string $html): string
    {
        $matched = preg_match('/<main\b.*<\/main>/s', $html, $matches);
        $this->assertSame(1, $matched, 'Expected to find the <main> detail content.');

        return $matches[0];
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
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields(), 'downloads' => [], 'downloads_unlink_error' => null]);

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
        // issue #855: the only entry point into the "Add download" page is this link, carrying
        // the current anime's id so the form preselects it.
        $this->assertStringContainsString('/downloads/new?anime=1', $html);
        // Scoped to the <main> detail content itself, not the whole page: base.html.twig's
        // top-nav "Add" menu (issue #834) can render a disabled "Search in plugins" item whenever
        // this test's container has no active filler plugin wired up — unrelated to whether this
        // page's own fields are disabled, which is what this assertion is actually about.
        $this->assertStringNotContainsString('disabled', $this->mainContent($html));
        $this->assertStringContainsString('https://shikimori.one/favicon.ico', $html);
        $this->assertStringContainsString('https://shikimori.one/animes/16498', $html);
        $this->assertStringContainsString('app-media://anime/1/cover_1720273812345.webp', $html);
        $this->assertStringContainsString('app-media://anime/1/screenshot_1720273812345.webp', $html);
    }

    /**
     * Issue #830: a source favicon can fail to load for reasons unrelated to the local catalog, in
     * which case inline-handlers.js swaps it for the neutral `globe` icon fallback already sitting
     * next to it in the markup — but only if that fallback is really its next sibling (see
     * inline-handlers.test.js for the handler's own behaviour when it is not). A substring
     * assertion would miss the fallback being removed, reordered or wrapped around the icon, so
     * this walks the actual DOM structure instead.
     */
    public function testShowRendersTheSourceFaviconFallbackAsItsNextSibling(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1');

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale('ru');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields(), 'downloads' => [], 'downloads_unlink_error' => null]);

        $document = new \DOMDocument();
        $document->loadHTML($html);
        $xpath = new \DOMXPath($document);

        $icons = $xpath->query('//img[contains(concat(" ", normalize-space(@class), " "), " anime-detail__source-icon ")]');
        $this->assertInstanceOf(\DOMNodeList::class, $icons);
        $this->assertSame(1, $icons->length);

        /** @var \DOMElement $icon */
        $icon = $icons->item(0);
        $fallback = $icon->nextSibling;
        while ($fallback instanceof \DOMText) {
            $fallback = $fallback->nextSibling;
        }

        $this->assertInstanceOf(\DOMElement::class, $fallback);
        $this->assertSame('span', $fallback->tagName);
        $this->assertStringContainsString('anime-detail__source-fallback', (string) $fallback->getAttribute('class'));
        $this->assertTrue($fallback->hasAttribute('hidden'));

        $svgs = $xpath->query('.//svg', $fallback);
        $this->assertInstanceOf(\DOMNodeList::class, $svgs);
        $this->assertSame(1, $svgs->length);
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
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'related', 'title' => 'Related titles', 'pluginName' => 'Shikimori', 'slot' => 'bottom']],
            'plugins_ui' => [],
            'fillable_fields' => $this->emptyFillableFields(),
            'downloads' => [],
            'downloads_unlink_error' => null,
        ]);

        $this->assertStringContainsString('hx-get="/plugin/animedb-shikimori/widget/related?entryId=1"', $html);
        // Issue #728: the entry-widget slot gets the same header as the catalog one (see
        // WidgetSlotsTemplateRenderingTest for the partial's own coverage of the header itself).
        $this->assertStringContainsString('class="plugin-widget-slot__heading"', $html);
        $this->assertStringContainsString('Related titles', $html);
        $this->assertStringContainsString('Shikimori', $html);
    }

    public function testShowRendersSideWidgetsInTheRightColumnAndBottomWidgetsAfterTheGallery(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/show.html.twig', [
            'anime' => $this->fullyPopulatedAnime(),
            'widgets' => [
                ['pluginId' => 'animedb-shikimori', 'widgetName' => 'side-one', 'title' => 'T1', 'pluginName' => 'P', 'slot' => 'side'],
                ['pluginId' => 'animedb-shikimori', 'widgetName' => 'bottom-one', 'title' => 'T2', 'pluginName' => 'P', 'slot' => 'bottom'],
            ],
            'plugins_ui' => [],
            'fillable_fields' => $this->emptyFillableFields(),
            'downloads' => [],
            'downloads_unlink_error' => null,
        ]);

        $side = strpos($html, '/widget/side-one');
        $bottom = strpos($html, '/widget/bottom-one');
        $this->assertNotFalse($side);
        $this->assertNotFalse($bottom);
        $this->assertSame(1, substr_count($html, '/widget/side-one'));
        $this->assertSame(1, substr_count($html, '/widget/bottom-one'));
        $this->assertGreaterThan(strpos($html, 'anime-detail__column--right'), $side);
        $this->assertLessThan(strpos($html, 'anime-detail__wide'), $side);
        $this->assertGreaterThan(strpos($html, 'anime-detail__wide'), $bottom);
        $this->assertGreaterThan(strpos($html, 'anime-detail__files'), $side);
        $sources = strpos($html, 'anime-detail__sources');
        $this->assertNotFalse($sources);
        $this->assertLessThan($sources, $side);
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
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields(), 'downloads' => [], 'downloads_unlink_error' => null]);

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
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields(), 'downloads' => [], 'downloads_unlink_error' => null]);

        $this->assertStringContainsString('data-control="catalog-back-link"', $html);
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
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->minimalAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields(), 'downloads' => [], 'downloads_unlink_error' => null]);

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
        $this->assertStringNotContainsString('anime-detail__gallery-empty', $html);
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
        $html = $twig->render('anime/show.html.twig', ['anime' => $anime, 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $this->emptyFillableFields(), 'downloads' => [], 'downloads_unlink_error' => null]);

        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('Путь не доступен', $html);
    }

    /**
     * Both the cover and the gallery sections get their own "fill from source" button once a
     * plugin actively supports the field (issue #507) - same button/dropdown macro
     * anime/_fill_button.html.twig shares between the card fragments, and the same
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
        $html = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $fillableFields, 'downloads' => [], 'downloads_unlink_error' => null]);

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

    /**
     * @param array<string, mixed>                       $anime
     * @param array<string, list<array<string, string>>> $fillableFields
     */
    private function renderShow(array $anime, string $locale = 'ru', ?array $fillableFields = null): string
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/'.$anime['id']);

        /** @var LocaleSwitcher $localeSwitcher */
        $localeSwitcher = self::getContainer()->get(LocaleSwitcher::class);
        $localeSwitcher->setLocale($locale);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('anime/show.html.twig', ['anime' => $anime, 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $fillableFields ?? $this->emptyFillableFields(), 'downloads' => [], 'downloads_unlink_error' => null]);
    }

    public function testHeaderCarriesTheTitleAndASubtitleWithJapaneseNameTypeAndYears(): void
    {
        $anime = $this->fullyPopulatedAnime();
        $anime['date_premiere'] = '2009-04-05';
        $anime['date_end'] = '2010-07-04';

        $html = $this->renderShow($anime, 'en');

        $this->assertMatchesRegularExpression('/<header class="anime-detail__header"[^>]*>\s*<div class="anime-detail__heading">\s*<h1 class="anime-detail__title"><bdi>Shingeki no Kyojin<\/bdi><\/h1>/', $html);
        $this->assertStringContainsString('<p class="anime-detail__subtitle">進撃の巨人 · TV Series · 2009–2010</p>', $html);
        // The title heading is no longer part of the cover fragment re-rendered by the cover fill.
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $mediaFragment = $twig->render('anime/_media.html.twig', ['anime' => $anime, 'fillable_fields' => $this->emptyFillableFields()]);
        $this->assertStringNotContainsString('<h1', $mediaFragment);
    }

    public function testSubtitleSkipsMissingPartsWithoutDanglingSeparators(): void
    {
        $anime = $this->minimalAnime();
        $anime['date_premiere'] = '2016-09-17';
        $anime['date_end'] = '2016-09-17';

        $html = $this->renderShow($anime, 'en');

        $this->assertStringContainsString('<p class="anime-detail__subtitle">Movie · 2016</p>', $html);

        $html = $this->renderShow($this->minimalAnime(), 'en');

        $this->assertStringContainsString('<p class="anime-detail__subtitle">Movie</p>', $html);
    }

    public function testDatesAreRenderedAsARangeOfUnbreakableDates(): void
    {
        $anime = $this->fullyPopulatedAnime();
        $anime['date_premiere'] = '2009-04-05';
        $anime['date_end'] = '2010-07-04';

        $html = $this->renderShow($anime, 'en');

        $this->assertStringContainsString('<span class="anime-detail__date">2009-04-05</span>–<wbr><span class="anime-detail__date">2010-07-04</span>', $html);
    }

    public function testStarsMarkTheCurrentRatingAndResetItOnASecondClick(): void
    {
        $anime = $this->fullyPopulatedAnime();
        $anime['user_rating'] = 3;

        $html = $this->renderShow($anime, 'en');

        $this->assertStringNotContainsString('<select name="user_rating"', $html);
        $this->assertSame(5, substr_count($html, 'class="anime-detail__star'));
        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'));
        $this->assertStringContainsString('aria-label="Rating: 3"', $html);
        $this->assertSame(3, substr_count($html, 'anime-detail__star--on'));
        // Another star commits its own value...
        $this->assertMatchesRegularExpression('/name="user_rating" value="5">\s*<button[^>]*id="anime-rating-star-1-5"/', $html);
        // ...and the current one posts an empty value, which the controller turns into "not rated".
        $this->assertMatchesRegularExpression('/name="user_rating" value="">\s*<button[^>]*id="anime-rating-star-1-3"[^>]*aria-pressed="true"/', $html);
        $this->assertStringContainsString('hx-post="/anime/1/editable/user_rating"', $html);
    }

    public function testNotesBlockIsAlwaysAnchoredButOnlyFilledWhenThereAreNotes(): void
    {
        $html = $this->renderShow($this->minimalAnime(), 'en');

        $this->assertMatchesRegularExpression('/<section[^>]*id="anime-notes-2"[^>]* hidden>/', $html);
        $this->assertStringNotContainsString('anime-detail__notes-text', $html);
        $this->assertStringContainsString('Add a note', $html);

        $html = $this->renderShow($this->fullyPopulatedAnime(), 'en');

        $this->assertDoesNotMatchRegularExpression('/<section[^>]*id="anime-notes-1"[^>]* hidden>/', $html);
        $this->assertStringContainsString('<p class="anime-detail__notes-text">Rewatch before the finale.</p>', $html);
        $this->assertStringNotContainsString('Add a note', $html);
    }

    public function testNotesEditFormIsRenderedInsideTheNotesBlockNotTheEditableOne(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1/editable/notes/edit');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $html = $twig->render('anime/_notes.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'editing' => true]);

        $this->assertMatchesRegularExpression('/<section[^>]*id="anime-notes-1"[^>]*>.*<textarea name="notes" rows="8"/s', $html);
        $this->assertStringContainsString('hx-post="/anime/1/editable/notes"', $html);
        $this->assertStringContainsString('hx-target="#anime-notes-1"', $html);
        $this->assertStringNotContainsString('anime-editable-1', $html);

        // Editing the first note: the (still empty) block must be visible.
        $html = $twig->render('anime/_notes.html.twig', ['anime' => $this->minimalAnime(), 'editing' => true]);

        $this->assertDoesNotMatchRegularExpression('/<section[^>]*id="anime-notes-2"[^>]* hidden>/', $html);
        $this->assertStringContainsString('<textarea', $html);
    }

    public function testNotesEditCancelReloadsTheNotesViewNotTheEditableBlock(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1/editable/notes/edit');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $html = $twig->render('anime/_notes.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'editing' => true]);

        $this->assertMatchesRegularExpression(
            '#hx-get="/anime/1/editable/notes"\s+hx-target="\#anime-notes-1"#',
            $html,
        );
    }

    public function testEditableFragmentCarriesTheOobSwapAttributeOnlyWhenRequested(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1/editable');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $params = ['anime' => $this->fullyPopulatedAnime(), 'editing' => null, 'error' => null];

        $oob = $twig->render('anime/_editable.html.twig', $params + ['oob' => true]);
        $this->assertMatchesRegularExpression('/id="anime-editable-1"[^>]* hx-swap-oob="outerHTML"/', $oob);

        $plain = $twig->render('anime/_editable.html.twig', $params);
        $this->assertStringContainsString('id="anime-editable-1"', $plain);
        $this->assertStringNotContainsString('hx-swap-oob', $plain);
    }

    public function testAddNoteEntryPointTargetsTheNotesBlock(): void
    {
        $html = $this->renderShow($this->minimalAnime(), 'en');

        $this->assertMatchesRegularExpression('/anime-detail__notes-add[^>]*hx-get="[^"]*notes\/edit"[^>]*hx-target="#anime-notes-2"/s', $html);
        $this->assertStringNotContainsString('<textarea', $html);
    }

    public function testHeaderFragmentIsAnOobSwapWithTheJapaneseNameWhenRequested(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1/fill/alternativeNames');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $html = $twig->render('anime/_header.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'oob' => true]);

        $this->assertStringContainsString('id="anime-header-1"', $html);
        $this->assertStringContainsString('hx-swap-oob="outerHTML"', $html);
    }

    public function testHeaderMenuLinksToTheEditPage(): void
    {
        $html = $this->renderShow($this->fullyPopulatedAnime(), 'en');

        $this->assertMatchesRegularExpression('#<ul class="dropdown-menu[^"]*">\s*<li><a class="dropdown-item" href="/anime/1/edit">Edit</a></li>#', $html);
    }

    public function testEditFormShowsErrorsNextToTheFieldsAndKeepsTheTypedValues(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1/edit');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/edit.html.twig', [
            'anime' => ['id' => 1, 'title' => 'Shingeki no Kyojin', 'type' => 'tv'],
            'is_series' => true,
            'form' => [
                'title' => '',
                'names' => [['name' => 'AoT', 'locale' => 'en', 'role' => 'short']],
                'descriptions' => [['locale' => 'en', 'text' => 'Typed text']],
                'genres' => ['action'],
                'themes' => [],
                'studios' => [],
                'new_studios' => ['Wit'],
                'demographic' => '',
                'date_premiere' => '2024-05-01',
                'date_end' => '2024-04-01',
                'duration_minutes' => '-5',
                'episodes_count' => '',
                'countries' => 'JP',
                'sources' => ['https://ok.example/', 'bad url'],
                'notes' => 'Typed notes',
                'cover_remove' => true,
            ],
            'cover' => 'abc.webp',
            'cover_max_bytes' => 5 * 1024 * 1024,
            'errors' => ['title' => 'anime_edit.error_title_required', 'date_end' => 'anime_edit.error_date_range', 'sources.1' => 'anime_edit.error_url_invalid', 'cover' => 'anime_edit.error_cover_invalid'],
            'genre_choices' => ['action', 'drama'],
            'theme_choices' => ['military'],
            'demographic_choices' => ['shounen'],
            'role_choices' => ['official', 'synonym', 'short'],
            'studio_choices' => [['id' => '7', 'name' => 'MAPPA']],
            'csrf_token_id' => 'anime_edit_1',
        ]);

        $this->assertStringContainsString('action="/anime/1/edit"', $html);
        $this->assertMatchesRegularExpression('#data-field-error="title">[^<]+#', $html);
        $this->assertStringContainsString('data-field-error="date_end"', $html);
        $this->assertStringContainsString('data-field-error="sources.1"', $html);
        $this->assertStringNotContainsString('data-field-error="sources.0"', $html);
        $this->assertStringContainsString('value="bad url"', $html);
        $this->assertStringContainsString('value="-5"', $html);
        $this->assertStringContainsString('Typed notes', $html);
        $this->assertStringContainsString('Typed text', $html);
        $this->assertStringContainsString('name="names[0][name]" value="AoT"', $html);
        $this->assertStringContainsString('name="sources[__INDEX__]"', $html, 'the add-row template must carry the index placeholder');
        $this->assertStringContainsString('name="episodes_count"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('<input type="file" id="anime-edit-cover" name="cover"', $html);
        $this->assertStringContainsString('src="app-media://anime/1/abc.webp"', $html);
        $this->assertMatchesRegularExpression('#name="cover_remove" value="1" checked#', $html);
        $this->assertStringContainsString('The file is not a PNG, JPEG or WebP image.', $html);
    }

    public function testEditFormListsStudiosAsCheckboxesWithTheEntryOnesCheckedAndFirst(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1/edit');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/edit.html.twig', [
            'anime' => ['id' => 1, 'title' => 'T', 'type' => 'tv'],
            'is_series' => true,
            'form' => [
                'title' => 'T', 'names' => [], 'descriptions' => [], 'genres' => [], 'themes' => [],
                'studios' => ['9'], 'new_studios' => [], 'demographic' => '', 'date_premiere' => '',
                'date_end' => '', 'duration_minutes' => '', 'episodes_count' => '', 'countries' => '',
                'sources' => [], 'notes' => '', 'cover_remove' => false,
            ],
            'cover' => null,
            'cover_max_bytes' => 5 * 1024 * 1024,
            'errors' => [],
            'genre_choices' => [],
            'theme_choices' => [],
            'demographic_choices' => [],
            'role_choices' => ['synonym'],
            'studio_choices' => [['id' => '3', 'name' => 'Bones'], ['id' => '7', 'name' => 'MAPPA'], ['id' => '9', 'name' => 'Toei']],
            'csrf_token_id' => 'anime_edit_1',
        ]);

        $this->assertStringNotContainsString('<select id="anime-edit-studios"', $html);
        $this->assertSame(3, preg_match_all('#<input type="checkbox" name="studios\[\]" value="(\d+)"( checked)?>#', $html, $m));
        $this->assertSame(['9', '3', '7'], $m[1], 'the entry studios go first');
        $this->assertSame([' checked', '', ''], $m[2]);
        $this->assertStringContainsString('data-control="choice-filter"', $html);
        $this->assertStringContainsString('data-filter-input', $html);
    }

    public function testEmptyAlternativeNamesAndGalleryAreHiddenAsWholeBlocks(): void
    {
        $html = $this->renderShow($this->minimalAnime(), 'en');

        $this->assertMatchesRegularExpression('/<section[^>]*id="anime-names-2"[^>]* hidden>/', $html);
        $this->assertMatchesRegularExpression('/<section[^>]*id="anime-gallery-2"[^>]* hidden>/', $html);

        $html = $this->renderShow($this->fullyPopulatedAnime(), 'en');

        $this->assertDoesNotMatchRegularExpression('/<section[^>]*id="anime-names-1"[^>]* hidden>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<section[^>]*id="anime-gallery-1"[^>]* hidden>/', $html);
    }

    public function testEmptyAlternativeNamesStayVisibleWhenAPluginCanFillThem(): void
    {
        $fillableFields = $this->emptyFillableFields();
        $fillableFields['alternativeNames'] = [['id' => 'animedb-shikimori', 'name' => 'Shikimori']];

        $html = $this->renderShow($this->minimalAnime(), 'en', $fillableFields);

        $this->assertDoesNotMatchRegularExpression('/<section[^>]*id="anime-names-2"[^>]* hidden>/', $html);
        $this->assertStringContainsString('hx-target="#anime-names-2"', $html);
    }

    public function testFilesBlockStaysVisibleWithoutStorageAndLinksToTheStoragePage(): void
    {
        $html = $this->renderShow($this->minimalAnime(), 'en');

        $this->assertStringContainsString('Not linked', $html);
        $this->assertStringContainsString('<a href="/storage">Link</a>', $html);
    }

    public function testSourcesAreAVerticalListLabelledWithTheDomainWithoutWww(): void
    {
        $anime = $this->fullyPopulatedAnime();
        $anime['sources'] = [['url' => 'https://www.example.org/a/1', 'domain' => 'www.example.org']];

        $html = $this->renderShow($anime, 'en');

        $this->assertStringContainsString('<span class="anime-detail__source-label">example.org</span>', $html);
        $this->assertStringContainsString('https://www.example.org/favicon.ico', $html);
    }

    public function testFillErrorIsRenderedInsideTheFragmentOfTheFieldThatCausedIt(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1');

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $params = ['anime' => $this->fullyPopulatedAnime(), 'fillable_fields' => $this->emptyFillableFields()];

        $infoWithoutError = $twig->render('anime/_info.html.twig', $params);
        $namesWithError = $twig->render('anime/_names.html.twig', $params + ['fill_error' => 'anime_detail.error_fill_not_found']);

        $this->assertStringNotContainsString('anime-detail__fill-error', $infoWithoutError);
        $this->assertMatchesRegularExpression('/<section[^>]*id="anime-names-1".*anime-detail__fill-error.*<\/section>/s', $namesWithError);
    }

    /**
     * Every fill form must target the root of the very fragment AnimeFillController renders for its
     * field (outerHTML swap), otherwise the response replaces an unrelated block.
     */
    #[DataProvider('fillTargetProvider')]
    public function testEachFillFormTargetsTheRootOfTheFragmentTheControllerRendersForItsField(string $field, string $template): void
    {
        self::bootKernel();
        $this->pushRequestWithSession('/anime/1');

        $fillableFields = $this->emptyFillableFields();
        $fillableFields[$field] = [['id' => 'animedb-shikimori', 'name' => 'Shikimori']];

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $show = $twig->render('anime/show.html.twig', ['anime' => $this->fullyPopulatedAnime(), 'widgets' => [], 'plugins_ui' => [], 'fillable_fields' => $fillableFields, 'downloads' => [], 'downloads_unlink_error' => null]);

        $this->assertSame(1, preg_match('/hx-post="\/anime\/1\/fill\/'.$field.'"\s+hx-target="#([^"]+)"/', $show, $matches), 'No fill form for '.$field);

        $fragment = $twig->render($template, ['anime' => $this->fullyPopulatedAnime(), 'fillable_fields' => $fillableFields]);
        $this->assertStringContainsString('id="'.$matches[1].'"', $fragment);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fillTargetProvider(): iterable
    {
        yield 'alternativeNames' => ['alternativeNames', 'anime/_names.html.twig'];
        yield 'cover' => ['cover', 'anime/_media.html.twig'];
        yield 'images' => ['images', 'anime/_gallery.html.twig'];

        foreach (['genres', 'themes', 'demographic', 'studios', 'durationMinutes', 'episodesCount', 'countries'] as $field) {
            yield $field => [$field, 'anime/_info.html.twig'];
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function localeProvider(): iterable
    {
        yield 'en has no Cyrillic' => ['en', []];
        yield 'ru has Russian labels' => ['ru', ['Информация', 'Файлы', 'Моё']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('localeProvider')]
    public function testCardShowsOnlyStringsOfTheActiveLocale(string $locale, array $expected): void
    {
        $anime = $this->fullyPopulatedAnime();
        $anime['date_premiere'] = '2009-04-05';
        $anime['date_end'] = '2010-07-04';
        $anime['summary'] = 'Plain ASCII summary.';
        $anime['names'] = [];
        $anime['storage'] = null;

        $fillableFields = array_map(static fn (): array => [['id' => 'animedb-shikimori', 'name' => 'Shikimori']], $this->emptyFillableFields());

        $html = $this->renderShow($anime, $locale, $fillableFields);

        foreach ($expected as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // main only: the page chrome is not part of the card.
        $main = $this->mainContent($html);
        if ($locale === 'en') {
            $this->assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $main);
            $this->assertStringNotContainsString('anime_detail.', $main);
        } else {
            $this->assertStringNotContainsString('Information', $main);
            $this->assertStringNotContainsString('Fill from source', $main);
            $this->assertStringNotContainsString('anime_detail.', $main);
        }
    }

    /**
     * Issue #735: base.html.twig now loads the host's own JS as a single bundle in <head>, and
     * {% block javascripts %} narrows to plugin assets only. A plugin script may rely on host
     * globals such as window.Controller, so the bundle tag must always precede it in the
     * rendered markup — not just happen to, by virtue of where each block sits in the template.
     */
    public function testShowRendersTheHostBundleBeforeAnyPluginScript(): void
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
            'widgets' => [],
            'plugins_ui' => [['css' => [], 'js' => ['/plugin/animedb-shikimori/asset/widget.js']]],
            'fillable_fields' => $this->emptyFillableFields(),
            'downloads' => [],
            'downloads_unlink_error' => null,
        ]);

        $hostBundlePosition = strpos($html, 'js/main.js');
        $pluginScriptPosition = strpos($html, '/plugin/animedb-shikimori/asset/widget.js');

        $this->assertNotFalse($hostBundlePosition);
        $this->assertNotFalse($pluginScriptPosition);
        $this->assertLessThan($pluginScriptPosition, $hostBundlePosition);
    }

    /**
     * Issue #735 (color-mode: #638, inline-handlers: #633): the host bundle must run before the
     * first paint and before the parser reaches the page's own markup, which only holds if the
     * tag sits inside <head> and has neither `defer` nor `async` — either attribute defers
     * execution past parsing and would silently bring back the light-flash and the unhandled
     * cover-image error the two issues fixed.
     */
    public function testShowRendersTheHostBundleAsASynchronousHeadScript(): void
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
            'widgets' => [],
            'plugins_ui' => [],
            'fillable_fields' => $this->emptyFillableFields(),
            'downloads' => [],
            'downloads_unlink_error' => null,
        ]);

        $hostBundlePosition = strpos($html, 'js/main.js');
        $headEndPosition = strpos($html, '</head>');

        $this->assertNotFalse($hostBundlePosition);
        $this->assertNotFalse($headEndPosition);
        $this->assertLessThan($headEndPosition, $hostBundlePosition, 'Host bundle <script> must be inside <head>');

        preg_match('/<script[^>]*src="[^"]*js\/main\.js[^"]*"[^>]*>/', $html, $matches);
        $hostBundleTag = $matches[0] ?? null;
        $this->assertNotNull($hostBundleTag, 'Host bundle <script> tag not found');

        $this->assertDoesNotMatchRegularExpression('/\bdefer\b/', $hostBundleTag, 'Host bundle <script> must not be deferred');
        $this->assertDoesNotMatchRegularExpression('/\basync\b/', $hostBundleTag, 'Host bundle <script> must not be async');
    }

    /**
     * Issue #857 review: every other test in this file passes an empty `downloads` list, so the
     * "Downloads for this entry" block's non-empty branch — the unlink button, its
     * `download_unlink_{id}` CSRF token, the `download_unlink` route, the hx-target/section id
     * pairing, the translated status, and the conflict error — was never actually rendered. A
     * mismatch in any of them (e.g. the token id drifting from what
     * DownloadUnlinkController::unlink() checks) would still leave every existing test green.
     */
    public function testShowRendersTheDownloadsBlockWithAnUnlinkButtonAndAConflictError(): void
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
            'widgets' => [],
            'plugins_ui' => [],
            'fillable_fields' => $this->emptyFillableFields(),
            'downloads' => [['id' => 42, 'info_hash' => str_repeat('a', 40), 'status' => 'completed', 'version' => 7]],
            'downloads_unlink_error' => 'anime_detail.downloads_unlink_conflict_error',
        ]);

        $this->assertStringContainsString('id="anime-downloads-1"', $html);
        $this->assertStringContainsString('hx-post="/downloads/42/unlink"', $html);
        $this->assertStringContainsString('hx-target="#anime-downloads-1"', $html);
        $this->assertStringContainsString('Завершено', $html);
        $this->assertStringContainsString('name="version" value="7"', $html);
        $this->assertStringContainsString('name="status" value="completed"', $html);
        $this->assertStringContainsString('Отвязать', $html);
        $this->assertStringContainsString('Состояние изменилось, обновите страницу.', $html);

        $formStart = strpos($html, 'hx-post="/downloads/42/unlink"');
        $this->assertNotFalse($formStart);
        $matched = preg_match('/name="_token" value="([^"]+)"/', $html, $matches, 0, $formStart);
        $this->assertSame(1, $matched, 'Expected a CSRF token field in the unlink form.');
        $token = $matches[1];

        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = self::getContainer()->get(CsrfTokenManagerInterface::class);
        $this->assertTrue($csrfTokenManager->isTokenValid(new CsrfToken('download_unlink_42', $token)));
        // The token is scoped to this exact row — neither a different row's id nor the bare
        // `download_unlink` id (a single id shared across all rows) must accept it.
        $this->assertFalse($csrfTokenManager->isTokenValid(new CsrfToken('download_unlink_43', $token)));
        $this->assertFalse($csrfTokenManager->isTokenValid(new CsrfToken('download_unlink', $token)));
    }

    /** @return iterable<string, array{string}> */
    public static function unfinishedStatuses(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'failed' => ['failed'];
    }

    #[DataProvider('unfinishedStatuses')]
    public function testShowRendersALinkToTheDownloadsPageInsteadOfAnUnlinkButtonForUnfinishedRows(string $status): void
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
            'widgets' => [],
            'plugins_ui' => [],
            'fillable_fields' => $this->emptyFillableFields(),
            'downloads' => [['id' => 42, 'info_hash' => str_repeat('a', 40), 'status' => $status, 'version' => 3]],
            'downloads_unlink_error' => null,
        ]);

        $this->assertStringContainsString('id="anime-downloads-1"', $html);
        $this->assertStringNotContainsString('hx-post="/downloads/42/unlink"', $html);
        $this->assertStringNotContainsString('Отвязать', $html);
        $this->assertStringContainsString('href="/downloads"', $html);
        $this->assertStringContainsString('Управлять на странице «Загрузки»', $html);
    }
}
