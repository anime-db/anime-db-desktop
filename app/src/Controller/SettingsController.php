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

namespace App\Controller;

use App\Entity\Enum\PaginationMode;
use App\Entity\Enum\ThemePreference;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\AvailableLocalesProvider;
use App\Service\Search\AnimeReindexService;
use App\Service\WsPublisher;
use Meilisearch\Exceptions\ExceptionInterface as MeilisearchExceptionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class SettingsController
{
    /**
     * Backend event name published on setTheme() and consumed by native/lifecycle/index.js over
     * /ws to re-read config.json and re-apply nativeTheme.themeSource without an app restart. Keep
     * this string in sync with THEME_CHANGED_EVENT in native/theme.js — a mismatch breaks
     * live-apply silently, the same lesson as ProxyController::PROXY_CHANGED_EVENT (issue #336),
     * which is why SettingsControllerTest cross-checks both sides against each other.
     */
    public const THEME_CHANGED_EVENT = 'theme.changed';

    /**
     * $availableLocalesProvider is the same locale set LocaleSubscriber negotiates against
     * (issue #84), extended by plugin locales (issue #453).
     */
    public function __construct(
        private readonly AvailableLocalesProvider $availableLocalesProvider,
        private readonly AppSettingsProvider $settings,
        private readonly WsPublisher $wsPublisher,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
        private readonly AnimeReindexService $reindexService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/settings', name: 'settings_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderIndex();
    }

    /**
     * Persists the chosen locale to %AppData%/config.json, then redirects back to
     * `settings_index` (issue #558).
     *
     * Historically this endpoint re-rendered the settings page in place instead of redirecting —
     * "no redirect to another URL, so the switch reads as an instant page refresh rather than a
     * navigation." Issue #558 supersedes that decision: a 303 back to the same URL is visually
     * indistinguishable from an in-place refresh, and redirecting removes the request/translator
     * syncing this endpoint used to do by hand, plus fixes the "confirm form resubmission" prompt
     * a POST rendered in place on GET refresh (F5) triggers.
     */
    #[Route('/settings', name: 'settings_set_locale', methods: ['POST'])]
    public function setLocale(Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken('settings_set_locale', $request);

        $locale = (string) $request->request->get('locale', '');
        if (!\in_array($locale, $this->availableLocalesProvider->all(), true)) {
            throw new BadRequestHttpException('Unknown locale.');
        }

        $this->settings->setLocale($locale);

        return new RedirectResponse($this->urlGenerator->generate('settings_index'), Response::HTTP_SEE_OTHER);
    }

    /**
     * Persists the chosen color scheme to %AppData%/config.json (issue #638), same
     * PRG shape as {@see self::setLocale()}.
     */
    #[Route('/settings/theme', name: 'settings_set_theme', methods: ['POST'])]
    public function setTheme(Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken('settings_set_theme', $request);

        $theme = ThemePreference::tryFrom((string) $request->request->get('themePreference', ''));
        if ($theme === null) {
            throw new BadRequestHttpException('Unknown theme preference.');
        }

        $this->settings->setThemePreference($theme);

        // Native layer already listens to every /ws event (see native/lifecycle/index.js); it
        // re-reads config.json itself rather than trusting this payload, so the theme value here
        // is informational only.
        $this->wsPublisher->publish(self::THEME_CHANGED_EVENT, ['theme' => $theme->value]);

        return new RedirectResponse($this->urlGenerator->generate('settings_index'), Response::HTTP_SEE_OTHER);
    }

    /**
     * Persists the chosen catalog pagination style to %AppData%/config.json (issue #665), same
     * PRG shape as {@see self::setTheme()}. Defaults to infinite scroll ({@see AppSettingsProvider::getPaginationMode()})
     * until the user picks classic pagination here — this is the only place that can.
     */
    #[Route('/settings/pagination-mode', name: 'settings_set_pagination_mode', methods: ['POST'])]
    public function setPaginationMode(Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken('settings_set_pagination_mode', $request);

        $mode = PaginationMode::tryFrom((string) $request->request->get('paginationMode', ''));
        if ($mode === null) {
            throw new BadRequestHttpException('Unknown pagination mode.');
        }

        $this->settings->setPaginationMode($mode);

        return new RedirectResponse($this->urlGenerator->generate('settings_index'), Response::HTTP_SEE_OTHER);
    }

    /**
     * Persists which catalog filter-panel sections are collapsed (issue #820), read back by
     * HomeController::index() so the template renders each section already collapsed/expanded
     * instead of flashing every section open before JS can react. Unlike every other setter
     * above, this one is fired by anime-list-filters.js in the background on every section
     * toggle click — no page reload — so it answers with a bare 204 rather than the PRG redirect
     * those form posts use, and reads its CSRF token from the JSON body (mirrors
     * AnimeLabelController::update()) rather than a form field, since there is no form here.
     */
    #[Route('/settings/filter-sections', name: 'settings_set_filter_sections', methods: ['POST'])]
    public function setFilterSections(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        $token = new CsrfToken('settings_filter_sections', (string) ($payload['token'] ?? ''));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $collapsed = $payload['collapsed'] ?? [];
        if (!\is_array($collapsed)) {
            throw new BadRequestHttpException('"collapsed" must be an array.');
        }

        $this->settings->setCollapsedFilterSections($collapsed);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Runs the same catalog reindex as bin/console app:search:reindex (issue #198), so a user
     * hitting a stale/broken search index has a recovery option that doesn't require the CLI.
     *
     * Redirects (POST-Redirect-GET) to `settings_search_index` with the outcome in the query
     * string (issue #822) — it used to render `settings/index.html.twig` directly, back when the
     * reindex button lived on the main settings page instead of its own.
     */
    #[Route('/settings/search/reindex', name: 'settings_search_reindex', methods: ['POST'])]
    public function reindexSearch(Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken('settings_search_reindex', $request);

        try {
            $this->reindexService->reindexAll();
            $status = 'success';
        } catch (MeilisearchExceptionInterface) {
            $status = 'error';
        }

        return new RedirectResponse($this->urlGenerator->generate('settings_search_index', ['status' => $status]), Response::HTTP_SEE_OTHER);
    }

    private function renderIndex(): Response
    {
        $locales = $this->availableLocalesProvider->all();
        $savedLocale = $this->settings->getLocale();
        $unavailableLocale = $savedLocale !== null && !\in_array($savedLocale, $locales, true) ? $savedLocale : null;

        return new Response($this->twig->render('settings/index.html.twig', [
            'availableLocales' => $locales,
            'unavailableLocale' => $unavailableLocale,
            'themePreference' => $this->settings->getThemePreference(),
            'paginationMode' => $this->settings->getPaginationMode(),
        ]));
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
