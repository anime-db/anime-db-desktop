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

use App\Service\AppSettingsProvider;
use App\Service\Search\AnimeReindexService;
use Meilisearch\Exceptions\ExceptionInterface as MeilisearchExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class SettingsController
{
    /**
     * @param list<string> $locales same app.locales container parameter LocaleSubscriber
     *                              negotiates against (issue #84) — plugin-provided locales are
     *                              out of scope until the plugin translation registration
     *                              mechanism is designed (issue #86 discussion)
     */
    public function __construct(
        private readonly array $locales,
        private readonly AppSettingsProvider $settings,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
        private readonly AnimeReindexService $reindexService,
    ) {
    }

    #[Route('/settings', name: 'settings_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderIndex();
    }

    /**
     * Persists the chosen locale to %AppData%/config.json and re-renders the settings page in
     * place — no redirect to another URL, so the switch reads as an instant page refresh rather
     * than a navigation.
     */
    #[Route('/settings', name: 'settings_set_locale', methods: ['POST'])]
    public function setLocale(Request $request): Response
    {
        $this->assertValidCsrfToken('settings_set_locale', $request);

        $locale = (string) $request->request->get('locale', '');
        if (!\in_array($locale, $this->locales, true)) {
            throw new BadRequestHttpException('Unknown locale.');
        }

        $this->settings->setLocale($locale);

        return $this->renderIndex();
    }

    /**
     * Runs the same catalog reindex as bin/console app:search:reindex (issue #198), so a user
     * hitting a stale/broken search index has a recovery option that doesn't require the CLI.
     */
    #[Route('/settings/search/reindex', name: 'settings_search_reindex', methods: ['POST'])]
    public function reindexSearch(Request $request): Response
    {
        $this->assertValidCsrfToken('settings_search_reindex', $request);

        try {
            $this->reindexService->reindexAll();
            $reindexStatus = 'success';
        } catch (MeilisearchExceptionInterface) {
            $reindexStatus = 'error';
        }

        return $this->renderIndex($reindexStatus);
    }

    private function renderIndex(?string $reindexStatus = null): Response
    {
        return new Response($this->twig->render('settings/index.html.twig', [
            'availableLocales' => $this->locales,
            'currentLocale' => $this->settings->getLocale() ?? ($this->locales[0] ?? null),
            'reindexStatus' => $reindexStatus,
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
