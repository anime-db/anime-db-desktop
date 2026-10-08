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
use Twig\Environment;

/**
 * Issue #720: an active catalog widget had no slot anywhere on the catalog page before this.
 * These pin the actual anime/list.html.twig markup — not just plugin/_widget_slots.html.twig in
 * isolation (see WidgetSlotsTemplateRenderingTest) — so a future change to the surrounding page
 * cannot silently drop the widgets row again.
 */
final class AnimeListTemplateRenderingTest extends KernelTestCase
{
    /**
     * base.html.twig reads app.request.locale, so a real request cycle needs one on the stack.
     * The filter-sections container also calls csrf_token() unconditionally (issue #820), which
     * reads/writes its token through the session of the current request — a real HTTP
     * request-response cycle has one already; this manual render needs one pushed by hand.
     */
    private function pushRequest(): void
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    public function testRendersACatalogWidgetSlotWithoutAnEntryIdWhenAWidgetIsActive(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/list.html.twig', [
            'showOnboarding' => false,
            'collapsedFilterSections' => [],
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'spotlight', 'title' => 'Spotlight', 'pluginName' => 'Shikimori']],
        ]);

        $this->assertStringContainsString('anime-list__widgets', $html);
        $this->assertStringContainsString('hx-get="/plugin/animedb-shikimori/widget/spotlight"', $html);
        $this->assertStringNotContainsString('entryId', $html);

        // The widgets row must sit ahead of the chips/grid, inside the main column - see the
        // issue's "Место слота" section for why (infinite scroll makes anything below the grid
        // unreachable, and the filters sidebar is a fixed-width area with a different role).
        $widgetsPosition = strpos($html, 'anime-list__widgets');
        $chipsPosition = strpos($html, 'anime-list-chips');
        $gridPosition = strpos($html, 'anime-list-grid');
        $this->assertNotFalse($widgetsPosition);
        $this->assertNotFalse($chipsPosition);
        $this->assertNotFalse($gridPosition);
        $this->assertLessThan($chipsPosition, $widgetsPosition);
        $this->assertLessThan($gridPosition, $widgetsPosition);
    }

    /**
     * Issue #728: the widgets row must sit ahead of .anime-list__toolbar, not inside
     * .anime-list__main below it as before — the toolbar itself is what now reads as the
     * boundary between a plugin's data and the user's own catalog.
     */
    public function testWidgetsRowPrecedesTheToolbarWhenAWidgetIsActive(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/list.html.twig', [
            'showOnboarding' => false,
            'collapsedFilterSections' => [],
            'widgets' => [['pluginId' => 'animedb-shikimori', 'widgetName' => 'spotlight', 'title' => 'Spotlight', 'pluginName' => 'Shikimori']],
        ]);

        $widgetsPosition = strpos($html, 'anime-list__widgets');
        $toolbarPosition = strpos($html, 'anime-list__toolbar');
        $this->assertNotFalse($widgetsPosition);
        $this->assertNotFalse($toolbarPosition);
        $this->assertLessThan($toolbarPosition, $widgetsPosition);
    }

    public function testOmitsTheWidgetsRowWhenNoCatalogWidgetIsActive(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/list.html.twig', [
            'showOnboarding' => false,
            'collapsedFilterSections' => [],
            'widgets' => [],
        ]);

        $this->assertStringNotContainsString('anime-list__widgets', $html);
    }

    /**
     * Acceptance (issue #820): a section named in collapsedFilterSections renders already
     * collapsed on first paint — aria-expanded="false" and its body hidden — instead of every
     * section flashing open before JS can react to the saved state.
     */
    public function testRendersASavedCollapsedSectionAlreadyCollapsed(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/list.html.twig', [
            'showOnboarding' => false,
            'collapsedFilterSections' => ['genres'],
            'widgets' => [],
        ]);

        $this->assertMatchesRegularExpression($this->sectionPattern('genres', expanded: false), $html);
        $this->assertMatchesRegularExpression($this->sectionPattern('studios', expanded: true), $html);
    }

    /**
     * Matches one <section data-filter-section="$section">...</section> block and asserts its
     * toggle button's aria-expanded and body "hidden" attribute agree with $expanded — "(?!.*
     * <section)" bounds the match to that one section instead of swallowing every section after
     * it, since the eight sections share no other closing delimiter in the markup.
     */
    private function sectionPattern(string $section, bool $expanded): string
    {
        $ariaExpanded = $expanded ? 'true' : 'false';
        $hidden = $expanded ? '' : ' hidden';

        return '/<section class="anime-list__filter-section" data-filter-section="'.preg_quote($section, '/').'">'
            .'(?:(?!<section).)*?'
            .'aria-expanded="'.$ariaExpanded.'"'
            .'(?:(?!<section).)*?'
            .'<div class="anime-list__filter-section-body"'.$hidden.'>'
            .'/s';
    }

    /**
     * The onboarding `<section>` on its own, excluding the rest of the page — in particular
     * base.html.twig's top-nav "Add" menu, which (issue #834) always links to storage_new/
     * anime_new regardless of which onboarding card is showing.
     */
    private function onboardingSection(string $html): string
    {
        $matched = preg_match('/<section class="anime-list__onboarding.*?<\/section>/s', $html, $matches);
        $this->assertSame(1, $matched, 'Expected to find the onboarding section.');

        return $matches[0];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderList(array $context): string
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('anime/list.html.twig', array_merge([
            'showOnboarding' => false,
            'hasScannableStorage' => false,
            'singleScannableStorageId' => null,
            'widgets' => [],
            'collapsedFilterSections' => [],
            'hasActiveFillerPlugin' => false,
            'noFillerState' => ['kind' => 'not_installed', 'url' => '/settings/market'],
        ], $context));
    }

    /**
     * Acceptance (issue #835), scenario (a): no records and no storage — the invitation shows the
     * "add storage" card (not "scan"), and none of the search/sort/filters UI is rendered.
     */
    public function testEmptyCatalogWithoutStorageShowsAddStorageCardAndHidesListControls(): void
    {
        $html = $this->renderList([
            'showOnboarding' => true,
            'hasScannableStorage' => false,
            'singleScannableStorageId' => null,
        ]);

        $this->assertStringContainsString('anime-list__onboarding', $html);
        $this->assertStringContainsString('href="/storage/new"', $html);
        $this->assertStringNotContainsString('anime-list__toolbar', $html);
        $this->assertStringNotContainsString('id="anime-list-sort"', $html);
        $this->assertStringNotContainsString('anime-list__filters"', $html);
    }

    /**
     * Issue #833, point 2: the empty-catalog "Search in plugins" card must link straight to the
     * search screen when a filler plugin is active — a mutation removing this branch's link
     * entirely survived every other test in the suite because nothing asserted on this card's
     * own href.
     */
    public function testEmptyCatalogSearchPluginsCardLinksToTheSearchScreenWhenAFillerPluginIsActive(): void
    {
        $html = $this->renderList([
            'showOnboarding' => true,
            'hasActiveFillerPlugin' => true,
        ]);

        $this->assertStringContainsString('href="/anime/search-plugins"', $html);
    }

    /**
     * Same card, the other branch: no active filler plugin — it must link to noFillerState's own
     * URL (the market, here) instead, and never to the search screen.
     */
    public function testEmptyCatalogSearchPluginsCardLinksToTheMarketWhenNoFillerPluginIsActive(): void
    {
        $html = $this->renderList([
            'showOnboarding' => true,
            'hasActiveFillerPlugin' => false,
            'noFillerState' => ['kind' => 'not_installed', 'url' => '/settings/market'],
        ]);

        $this->assertStringContainsString('href="/settings/market"', $html);
        $this->assertStringNotContainsString('href="/anime/search-plugins"', $html);
    }

    /**
     * Scenario (b): no records, but a scannable storage exists — the invitation swaps to the
     * "scan storage" card and, with exactly one candidate, submits that storage's scan directly.
     */
    public function testEmptyCatalogWithOneScannableStorageShowsScanStorageCard(): void
    {
        $html = $this->renderList([
            'showOnboarding' => true,
            'hasScannableStorage' => true,
            'singleScannableStorageId' => 7,
        ]);

        $this->assertStringContainsString('action="/storage/7/scan"', $html);
        // Scoped to the onboarding section itself, not the whole page: base.html.twig's top-nav
        // "Add" menu (issue #834) always links to storage_new, regardless of this scenario — only
        // the onboarding card's own link is what this scenario must not show.
        $this->assertStringNotContainsString('href="/storage/new"', $this->onboardingSection($html));
    }

    /**
     * Scenario (b, variant): more than one scannable storage exists — the card links to the
     * storage list instead of guessing which one to scan.
     */
    public function testEmptyCatalogWithMultipleScannableStoragesLinksToStorageList(): void
    {
        $html = $this->renderList([
            'showOnboarding' => true,
            'hasScannableStorage' => true,
            'singleScannableStorageId' => null,
        ]);

        $this->assertStringContainsString('href="/storage"', $html);
        $this->assertStringNotContainsString('action="/storage/', $html);
    }

    /**
     * Scenario (c): at least one record exists — the ordinary catalog renders, with no onboarding
     * block at all.
     */
    public function testNonEmptyCatalogShowsOrdinaryListWithoutOnboarding(): void
    {
        $html = $this->renderList(['showOnboarding' => false]);

        $this->assertStringNotContainsString('anime-list__onboarding', $html);
        $this->assertStringContainsString('anime-list__toolbar', $html);
        $this->assertStringContainsString('id="anime-list-sort"', $html);
        $this->assertStringContainsString('anime-list__filters"', $html);
    }

    /** Issue #953: the v1 import entry is part of the empty-catalog invitation. */
    public function testEmptyCatalogOffersTheImportFromV1(): void
    {
        $html = $this->renderList(['showOnboarding' => true, 'hasActiveFillerPlugin' => true, 'hasScannableStorage' => false]);

        $section = $this->onboardingSection($html);
        $this->assertStringContainsString('data-control="onboarding-import-v1"', $section);
        $this->assertStringContainsString('id="onboarding-import-v1-pick"', $section);
        $this->assertStringContainsString('Import from AnimeDB v1', $section);
    }

    /** Issue #953: no import into a catalog that already holds entries — it would have to merge. */
    public function testNonEmptyCatalogDoesNotOfferTheImportFromV1(): void
    {
        $html = $this->renderList(['showOnboarding' => false]);

        $this->assertStringNotContainsString('onboarding-import-v1', $html);
    }
}
