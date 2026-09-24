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

use App\Service\Plugin\CatalogWidgetRegistry;
use App\Service\Plugin\EntryWidgetRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Issue #742: the catalog placement's hard limit (2) already equals the soft recommended limit
 * that used to be shared with the entry placement, so `activeCount > recommendedLimit` could
 * never be true for the catalog section — the warning branch was dead code. This pins the fix:
 * the catalog section never renders the warning regardless of how many widgets are active, while
 * the entry section (which keeps its own {@see EntryWidgetRegistry::RECOMMENDED_LIMIT}) still
 * renders it once exceeded.
 */
final class PluginWidgetsSettingsTemplateRenderingTest extends KernelTestCase
{
    /** @return list<array{pluginId: string, widgetName: string, active: bool, title: string, description: string, pluginName: string}> */
    private function oneWidgetRow(): array
    {
        return [[
            'pluginId' => 'animedb-shikimori',
            'widgetName' => 'related',
            'active' => true,
            'title' => 'Related anime',
            'description' => '',
            'pluginName' => 'Shikimori',
        ]];
    }

    private function pushRequestWithSession(): void
    {
        $request = Request::create('/settings/plugins/widgets');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    private function render(int $entryActiveCount, int $catalogActiveCount): string
    {
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('settings/plugin/widgets.html.twig', [
            'entryWidgets' => $this->oneWidgetRow(),
            'catalogWidgets' => $this->oneWidgetRow(),
            'entryActiveCount' => $entryActiveCount,
            'catalogActiveCount' => $catalogActiveCount,
            'entryHardLimit' => EntryWidgetRegistry::HARD_LIMIT,
            'catalogHardLimit' => CatalogWidgetRegistry::HARD_LIMIT,
            'recommendedLimit' => EntryWidgetRegistry::RECOMMENDED_LIMIT,
            'error' => null,
            'limitReached' => 0,
        ]);
    }

    /** @return iterable<string, array{int}> */
    public static function everyReachableCatalogActiveCount(): iterable
    {
        // 0..CatalogWidgetRegistry::HARD_LIMIT are the counts the catalog section can actually be
        // rendered with — enabling a third widget is rejected before it reaches this template.
        // The last case is deliberately above the limit: unreachable through the UI, but it is the
        // only input that would make the warning branch true if the template kept it.
        yield 'zero active' => [0];
        yield 'one active' => [1];
        yield 'at the hard limit' => [2];
        yield 'above the hard limit' => [3];
    }

    #[DataProvider('everyReachableCatalogActiveCount')]
    public function testCatalogSectionNeverShowsTheRecommendationWarning(int $catalogActiveCount): void
    {
        self::bootKernel();

        $html = $this->render(entryActiveCount: 0, catalogActiveCount: $catalogActiveCount);

        $this->assertStringNotContainsString('active widgets is not recommended', $html);
    }

    public function testEntrySectionShowsTheRecommendationWarningOnceItIsExceeded(): void
    {
        self::bootKernel();

        $html = $this->render(entryActiveCount: 3, catalogActiveCount: 0);

        $warningText = 'More than 2 active widgets is not recommended';
        $this->assertStringContainsString($warningText, $html);

        $entryHeadingPos = strpos($html, '<h2>Anime detail page</h2>');
        $catalogHeadingPos = strpos($html, '<h2 class="mt-4">Catalog</h2>');
        $warningPos = strpos($html, $warningText);
        $this->assertNotFalse($entryHeadingPos);
        $this->assertNotFalse($catalogHeadingPos);
        $this->assertNotFalse($warningPos);
        $this->assertGreaterThan($entryHeadingPos, $warningPos);
        $this->assertLessThan($catalogHeadingPos, $warningPos);
    }

    public function testEntrySectionDoesNotShowTheRecommendationWarningAtExactlyTheRecommendedLimit(): void
    {
        self::bootKernel();

        $html = $this->render(entryActiveCount: EntryWidgetRegistry::RECOMMENDED_LIMIT, catalogActiveCount: 0);

        $this->assertStringNotContainsString('active widgets is not recommended', $html);
    }
}
