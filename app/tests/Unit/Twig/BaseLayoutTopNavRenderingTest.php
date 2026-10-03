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

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Service\Plugin\FillerAvailabilityPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Acceptance (issue #834): base.html.twig's top menu no longer has a "Catalog" link to highlight —
 * it was replaced by a word-mark that is never highlighted (see base.html.twig's own comment on
 * `current_nav_section`). "Settings" is still highlighted for every `settings_*` and `storage_*`
 * route except the ones that are a step of adding entries to the catalog rather than a settings
 * screen: `storage_scan_prompt` (issue #825) and `storage_scan_progress` (issue #834). Removing
 * either special case from base.html.twig would make
 * {@see self::testScanPromptAndScanProgressHighlightNothing()} fail.
 */
final class BaseLayoutTopNavRenderingTest extends KernelTestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function noHighlightRouteProvider(): iterable
    {
        yield 'home_index' => ['home_index'];
        yield 'an anime_* route' => ['anime_editable_view'];
        yield 'storage_scan_prompt' => ['storage_scan_prompt'];
        yield 'storage_scan_progress' => ['storage_scan_progress'];
    }

    /** @return iterable<string, array{0: string}> */
    public static function settingsRouteProvider(): iterable
    {
        yield 'settings_index' => ['settings_index'];
        yield 'a storage_* route other than scan-prompt/scan-progress' => ['storage_index'];
    }

    #[DataProvider('noHighlightRouteProvider')]
    public function testCatalogLikeRoutesHighlightNeitherWordmarkNorSettings(string $route): void
    {
        $html = $this->renderBaseLayout($route);

        self::assertNothingHighlighted($html);
    }

    #[DataProvider('settingsRouteProvider')]
    public function testSettingsRoutesHighlightSettingsNotWordmark(string $route): void
    {
        $html = $this->renderBaseLayout($route);

        self::assertSettingsLinkActive($html);
    }

    /**
     * Acceptance (issue #825): `storage/scan_prompt.html.twig` extends `base.html.twig` directly,
     * not `settings/_layout.html.twig` — it is a catalog onboarding step, not a settings page, so
     * it must never carry the settings sidebar. Switching its `{% extends %}` to the settings
     * layout would make this test fail.
     */
    public function testScanPromptRendersWithoutTheSettingsSidebar(): void
    {
        self::bootKernel();
        $this->pushRequest('storage_scan_prompt');

        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, 7);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('storage/scan_prompt.html.twig', ['storage' => $storage]);

        self::assertStringNotContainsString('settings-sidebar', $html);
    }

    /**
     * Regression (issue #834 review): the Add menu's "Search in plugins" branch — active link vs.
     * disabled item with a dynamic `nav.add_menu_search_plugins_hint_<kind>` trans key, and, for
     * `not_installed`, a URL swapped in the template for `settings_market_index` with
     * `?feature=filler` tacked on — had no render test at all, so a typo in the trans key or a lost
     * `feature=filler` would pass CI unnoticed.
     */
    public function testAddMenuRendersActiveSearchPluginsLinkWhenAFillerIsActive(): void
    {
        $presenter = $this->createStub(FillerAvailabilityPresenter::class);
        $presenter->method('hasActiveFiller')->willReturn(true);

        $html = $this->renderBaseLayoutWithFillerPresenter('home_index', $presenter);

        $this->assertStringContainsString('<a class="dropdown-item" href="/anime/search-plugins">', $html);
        $this->assertStringNotContainsString('dropdown-item disabled', $html);
    }

    public function testAddMenuRendersNotInstalledHintLinkingToMarketWithFillerFeatureFilter(): void
    {
        $presenter = $this->createStub(FillerAvailabilityPresenter::class);
        $presenter->method('hasActiveFiller')->willReturn(false);
        $presenter->method('describeUnavailable')->willReturn(['kind' => 'not_installed', 'url' => '/settings/market']);

        $html = $this->renderBaseLayoutWithFillerPresenter('home_index', $presenter);

        $this->assertStringContainsString('dropdown-item disabled', $html);
        $this->assertStringContainsString('No plugin source is installed.', $html);
        $this->assertStringContainsString('<a class="dropdown-item small" href="/settings/market?feature=filler">Open the market →</a>', $html);
    }

    public function testAddMenuRendersDisabledHintLinkingToThePresentersOwnUrl(): void
    {
        $presenter = $this->createStub(FillerAvailabilityPresenter::class);
        $presenter->method('hasActiveFiller')->willReturn(false);
        $presenter->method('describeUnavailable')->willReturn(['kind' => 'disabled', 'url' => '/settings/plugins/animedb-shikimori']);

        $html = $this->renderBaseLayoutWithFillerPresenter('home_index', $presenter);

        $this->assertStringContainsString('dropdown-item disabled', $html);
        $this->assertStringContainsString('<a class="dropdown-item small" href="/settings/plugins/animedb-shikimori">Configure the plugin →</a>', $html);
        $this->assertStringNotContainsString('feature=filler', $html);
    }

    /**
     * Regression (issue #872): the hint link used to live in a plain `<div class="app-nav__hint">`
     * outside any `.dropdown-item`, so Bootstrap's dropdown arrow-key navigation skipped over it —
     * only Tab could reach it. It must be its own `.dropdown-item` `<li>` to be keyboard-navigable
     * the same way as every other menu entry.
     */
    public function testAddMenuHintLinkIsItsOwnKeyboardNavigableMenuItem(): void
    {
        $presenter = $this->createStub(FillerAvailabilityPresenter::class);
        $presenter->method('hasActiveFiller')->willReturn(false);
        $presenter->method('describeUnavailable')->willReturn(['kind' => 'not_installed', 'url' => '/settings/market']);

        $html = $this->renderBaseLayoutWithFillerPresenter('home_index', $presenter);

        $this->assertStringNotContainsString('app-nav__hint', $html);
        $this->assertMatchesRegularExpression('#<li><a class="dropdown-item small"#', $html);
    }

    /**
     * Regression (issue #834 review): the Add menu's "Add storage…"/"Empty entry" `<li>`s have no
     * conditional around them in base.html.twig, so dropping either one from the markup passed no
     * existing test.
     */
    public function testAddMenuAlwaysRendersTheAddStorageAndEmptyEntryLinks(): void
    {
        $html = $this->renderBaseLayout('home_index');

        $this->assertStringContainsString('href="/storage/new"', $html);
        $this->assertStringContainsString('href="/anime/new"', $html);
    }

    private function renderBaseLayoutWithFillerPresenter(string $route, FillerAvailabilityPresenter $presenter): string
    {
        self::bootKernel();
        self::getContainer()->set(FillerAvailabilityPresenter::class, $presenter);
        $this->pushRequest($route);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('base.html.twig');
    }

    private function renderBaseLayout(string $route): string
    {
        self::bootKernel();
        $this->pushRequest($route);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('base.html.twig');
    }

    private function pushRequest(string $route): void
    {
        $request = Request::create('/');
        $request->attributes->set('_route', $route);
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    private static function assertNothingHighlighted(string $html): void
    {
        self::assertStringNotContainsString('app-nav__link--active', self::extractLinkClass($html, 'home_index'), 'Expected the word-mark to stay inactive.');
        self::assertStringNotContainsString('app-nav__link--active', self::extractLinkClass($html, 'settings_index'), 'Expected the settings link to stay inactive.');
    }

    private static function assertSettingsLinkActive(string $html): void
    {
        self::assertStringContainsString('app-nav__link--active', self::extractLinkClass($html, 'settings_index'), 'Expected the settings link to be active.');
        self::assertStringNotContainsString('app-nav__link--active', self::extractLinkClass($html, 'home_index'), 'Expected the word-mark to stay inactive.');
    }

    private static function extractLinkClass(string $html, string $routeName): string
    {
        /** @var UrlGeneratorInterface $urlGenerator */
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);
        $href = $urlGenerator->generate($routeName);

        $matched = preg_match('/<a class="([^"]*)" href="'.preg_quote($href, '/').'"/', $html, $matches);
        self::assertSame(1, $matched, \sprintf('Expected to find a top-nav link to "%s" (route "%s").', $href, $routeName));

        return $matches[1];
    }
}
