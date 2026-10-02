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
