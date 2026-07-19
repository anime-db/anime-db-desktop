<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Anime;
use App\Entity\SyncReviewItem;
use App\Repository\AnimeRepository;
use App\Service\Sync\SyncReviewService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * "Requires attention" settings page (issue #269): lists unresolved SyncReviewItem rows raised
 * by a sync run (issue #267) and lets a user mark one resolved. Merging duplicate Anime records
 * into one is a separate, not-yet-designed catalog feature and stays out of scope here — this
 * controller only displays the review queue and flips resolved_at.
 */
final class SyncReviewController
{
    public function __construct(
        private readonly SyncReviewService $syncReview,
        private readonly AnimeRepository $animeRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/settings/sync-review', name: 'settings_sync_review_index', methods: ['GET'])]
    public function index(): Response
    {
        $items = $this->syncReview->findUnresolved();

        return new Response($this->twig->render('settings/sync_review/index.html.twig', [
            'items' => $items,
            'duplicateClusters' => $this->duplicateClusters($items),
        ]));
    }

    #[Route('/settings/sync-review/{id}/resolve', name: 'settings_sync_review_resolve', methods: ['POST'])]
    public function resolve(SyncReviewItem $item, Request $request): RedirectResponse
    {
        $this->assertValidCsrfToken('settings_sync_review_resolve_'.$item->id, $request);

        $this->syncReview->resolve($item);

        return new RedirectResponse($this->urlGenerator->generate('settings_sync_review_index'));
    }

    /**
     * Resolves each item's payload anime_ids to Anime entities for display. Only
     * SyncReviewItemKind::PotentialDuplicate carries anime_ids today, so other kinds simply
     * resolve to an empty cluster here — no per-kind branching needed until a kind with a
     * different payload shape actually exists (issue #217).
     *
     * @param SyncReviewItem[] $items
     *
     * @return array<int, list<Anime>>
     */
    private function duplicateClusters(array $items): array
    {
        $clusters = [];
        foreach ($items as $item) {
            $id = $item->id ?? throw new \LogicException('SyncReviewItem id must be set after persisting');

            /** @var list<int> $animeIds */
            $animeIds = $item->payload['anime_ids'] ?? [];
            $animeById = $this->animeRepository->findByIds($animeIds);

            $clusters[$id] = array_values(array_filter(array_map(
                static fn (int $animeId) => $animeById[$animeId] ?? null,
                $animeIds,
            )));
        }

        return $clusters;
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
