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
 * Issue #833 review: pins two accessibility/behaviour requirements for the "search in plugins"
 * screen's own templates that nothing else in the suite asserts on directly — a candidate must
 * be a real `<button>` (keyboard/AT operable, not a plain clickable `<li>`), and the preview
 * panel's live region must announce every swap, including an empty one.
 */
final class SearchPluginsTemplateRenderingTest extends KernelTestCase
{
    private function pushRequest(): void
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    public function testGroupRendersEachCandidateAsAButton(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/search_plugins/_group.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'state' => 'results',
            'candidates' => [['name' => 'Trigun', 'externalId' => '1']],
            'query' => 'Trigun',
        ]);

        $this->assertStringContainsString('<button', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    /**
     * Issue #848, point 1: without a shared `hx-sync`, clicking candidate A and then candidate B
     * before A's preview response lands can let A's response arrive last and overwrite B's
     * preview — the "Add" button would then add A while the screen still shows B. Every candidate
     * button must synchronize against the same `#search-plugins-preview` selector so a second
     * click aborts the first click's still-pending request instead of racing it.
     */
    public function testGroupCandidateButtonsSynchronizeAgainstThePreviewPanel(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/search_plugins/_group.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'state' => 'results',
            'candidates' => [['name' => 'Trigun', 'externalId' => '1']],
            'query' => 'Trigun',
        ]);

        $this->assertStringContainsString('hx-sync="#search-plugins-preview:replace"', $html);
    }

    /**
     * Issue #848, point 5: _results.html.twig's placeholder used to wrap its `hx-get`/`hx-trigger`
     * in a nested `<div>` inside the `<section id="search-plugins-group-...">`. group()'s own
     * response is again a `<section>` with that same id, so swapping the nested div's outerHTML
     * left two elements sharing one id. The placeholder must now put `hx-get`/`hx-trigger`/
     * `hx-swap` directly on the `<section>` itself, with no nested `<section>` or `<div hx-get`
     * inside it.
     */
    public function testResultsPlaceholderPutsHxGetDirectlyOnTheSectionWithNoNestedElement(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/search_plugins/_results.html.twig', [
            'query' => 'Trigun',
            'pluginIds' => ['animedb-shikimori'],
        ]);

        $this->assertSame(1, preg_match_all('/<section[^>]*id="search-plugins-group-animedb-shikimori"/', $html), 'exactly one section with this id, no nested duplicate');
        $this->assertMatchesRegularExpression(
            '/<section[^>]*id="search-plugins-group-animedb-shikimori"[^>]*hx-get=/',
            $html,
        );
        $this->assertStringNotContainsString('<div hx-get', $html, 'the placeholder must carry hx-get itself, not wrap it in a nested div');
    }

    /**
     * Issue #848, point 2: a brand new search must not leave the previous candidate's preview
     * (with its still-working "Add" button) on screen. results() rides an out-of-band swap that
     * clears #search-plugins-preview's content on every response, regardless of whether the query
     * is empty.
     */
    public function testResultsClearsThePreviewPanelViaAnOutOfBandSwap(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $withQuery = $twig->render('anime/search_plugins/_results.html.twig', [
            'query' => 'Trigun',
            'pluginIds' => ['animedb-shikimori'],
        ]);
        $this->assertStringContainsString('id="search-plugins-preview" hx-swap-oob="innerHTML"', $withQuery);

        $withoutQuery = $twig->render('anime/search_plugins/_results.html.twig', [
            'query' => '',
            'pluginIds' => ['animedb-shikimori'],
        ]);
        $this->assertStringContainsString('id="search-plugins-preview" hx-swap-oob="innerHTML"', $withoutQuery);
    }

    public function testIndexPreviewPanelHasAnAriaLiveRegion(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/search_plugins/index.html.twig', [
            'query' => '',
            'hasActiveFiller' => true,
            'noFillerState' => null,
            'error' => null,
        ]);

        $this->assertMatchesRegularExpression(
            '/<aside[^>]*id="search-plugins-preview"[^>]*aria-live="polite"/',
            $html,
        );
    }

    /**
     * Issue #848, point 4 (review fix): a plain `hx-push-url="true"` on this form would push the
     * URL of the request the form itself fires - the fragment endpoint
     * `anime_search_plugins_results` - not this screen's own URL. A browser "back" or an F5
     * against that fragment URL then renders the bare HTML fragment with no layout, form, or
     * styles. History is pushed from the server instead, via the `HX-Push-Url` response header
     * set in {@see \App\Controller\AnimeSearchPluginsController::results()}, so this template
     * must not reintroduce the client-side attribute.
     */
    public function testIndexFormDoesNotPushTheFragmentUrlItself(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/search_plugins/index.html.twig', [
            'query' => '',
            'hasActiveFiller' => true,
            'noFillerState' => null,
            'error' => null,
        ]);

        $this->assertStringNotContainsString('hx-push-url', $html);
    }

    /**
     * Issue #848, point 2 (review fix): _results.html.twig's OOB clear only fires at the moment a
     * new search's results arrive - a preview request for a candidate from the *previous* search
     * can still be in flight and land afterwards, putting a stale candidate with a working "Add"
     * button back on screen. The search form must join the candidate buttons' own `hx-sync` group
     * (_group.html.twig) with the `replace` strategy, so firing a new search aborts any such
     * pending preview request.
     */
    public function testIndexFormAbortsAPendingPreviewRequestWhenANewSearchFires(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/search_plugins/index.html.twig', [
            'query' => '',
            'hasActiveFiller' => true,
            'noFillerState' => null,
            'error' => null,
        ]);

        $this->assertStringContainsString('hx-sync="#search-plugins-preview:replace"', $html);
    }

    /**
     * Issue #848, point 4: a non-empty `q` (restored from `?q=` or a redirect back from
     * fill_not_found/a conflict) must fire the search automatically on page load; a blank query
     * must not trigger a round trip for nothing.
     */
    public function testIndexResultsContainerAutoLoadsOnlyWhenQueryIsNotEmpty(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $withQuery = $twig->render('anime/search_plugins/index.html.twig', [
            'query' => 'Trigun',
            'hasActiveFiller' => true,
            'noFillerState' => null,
            'error' => null,
        ]);
        $this->assertMatchesRegularExpression(
            '/<div[^>]*id="search-plugins-results"[^>]*hx-trigger="load"/',
            $withQuery,
        );

        $withoutQuery = $twig->render('anime/search_plugins/index.html.twig', [
            'query' => '',
            'hasActiveFiller' => true,
            'noFillerState' => null,
            'error' => null,
        ]);
        $this->assertStringNotContainsString('hx-trigger="load"', $withoutQuery);
    }

    /**
     * Issue #848, point 3: a conflict error must render as a translated message plus, when a link
     * is supplied, an anchor to the owning record - never raw HTML smuggled through `|raw`.
     */
    public function testIndexRendersTheConflictErrorMessageWithItsLink(): void
    {
        self::bootKernel();
        $this->pushRequest();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('anime/search_plugins/index.html.twig', [
            'query' => '',
            'hasActiveFiller' => true,
            'noFillerState' => null,
            'error' => [
                'messageKey' => 'search_plugins.error_conflict_claimed',
                'messageParams' => [],
                'link' => ['url' => '/anime/42', 'labelKey' => 'search_plugins.error_conflict_claimed_link'],
            ],
        ]);

        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('<a href="/anime/42"', $html);
    }
}
