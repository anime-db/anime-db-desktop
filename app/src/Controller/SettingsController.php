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

use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\SyncReviewItem;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\AvailableLocalesProvider;
use App\Service\Search\AnimeReindexService;
use App\Service\Sync\SyncReviewService;
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
     * $availableLocalesProvider is the same locale set LocaleSubscriber negotiates against
     * (issue #84), extended by plugin locales (issue #453).
     */
    public function __construct(
        private readonly AvailableLocalesProvider $availableLocalesProvider,
        private readonly AppSettingsProvider $settings,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
        private readonly AnimeReindexService $reindexService,
        private readonly SyncReviewService $syncReview,
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
        $locales = $this->availableLocalesProvider->all();
        $savedLocale = $this->settings->getLocale();
        $unavailableLocale = $savedLocale !== null && !\in_array($savedLocale, $locales, true) ? $savedLocale : null;

        return new Response($this->twig->render('settings/index.html.twig', [
            'availableLocales' => $locales,
            'unavailableLocale' => $unavailableLocale,
            'reindexStatus' => $reindexStatus,
            'needsCorrectionCount' => $this->needsCorrectionCount(),
        ]));
    }

    /**
     * Badge count for the "Requires attention" settings link (issue #382): unresolved
     * NeedsCorrection items specifically, not every SyncReviewItem kind — it is the one kind a
     * user cannot otherwise notice until they open the page.
     */
    private function needsCorrectionCount(): int
    {
        return \count(array_filter(
            $this->syncReview->findUnresolved(),
            static fn (SyncReviewItem $item): bool => $item->kind === SyncReviewItemKind::NeedsCorrection,
        ));
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
