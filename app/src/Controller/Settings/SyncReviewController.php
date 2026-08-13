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

namespace App\Controller\Settings;

use App\Entity\Anime;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\SyncReviewItem;
use App\Repository\AnimeRepository;
use App\Service\Sync\SyncConvergenceService;
use App\Service\Sync\SyncProjection;
use App\Service\Sync\SyncReviewService;
use Doctrine\ORM\EntityManagerInterface;
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
 *
 * NeedsCorrection (issue #382) additionally lets the user pick which participant's candidate
 * wins: {@see resolve()} forwards that pick to {@see SyncConvergenceService::applyManualResolution()}
 * before flipping resolved_at, same as the engine's own reconcile() winner does for the other
 * kinds. Its resolve form posts via HTMX (unlike the plain-POST forms the other kinds still use),
 * so a successful pick removes just that item from the list without a full page reload.
 */
final class SyncReviewController
{
    public function __construct(
        private readonly SyncReviewService $syncReview,
        private readonly AnimeRepository $animeRepository,
        private readonly SyncConvergenceService $syncConvergenceService,
        private readonly EntityManagerInterface $entityManager,
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
            'deletionDetails' => $this->deletionDetails($items),
            'needsCorrectionDetails' => $this->needsCorrectionDetails($items),
        ]));
    }

    #[Route('/settings/sync-review/{id}/resolve', name: 'settings_sync_review_resolve', methods: ['POST'])]
    public function resolve(SyncReviewItem $item, Request $request): Response
    {
        $this->assertValidCsrfToken('settings_sync_review_resolve_'.$item->id, $request);

        if ($item->kind === SyncReviewItemKind::NeedsCorrection) {
            $this->applyChosenCandidate($item, $request);
        }

        $this->syncReview->resolve($item);

        if ($request->headers->get('HX-Request') === 'true') {
            return new Response('');
        }

        return new RedirectResponse($this->urlGenerator->generate('settings_sync_review_index'));
    }

    /**
     * Resolves each item's payload anime_ids to Anime entities for display. Only
     * SyncReviewItemKind::PotentialDuplicate carries anime_ids; deletion kinds (issue #217) carry
     * a single anime_id instead and are resolved by {@see self::deletionDetails()}, so they
     * simply resolve to an empty cluster here.
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

    /**
     * Display data for source-side removal items (issue #217): the affected Anime plus the source
     * it was removed from and the still-linked sources, from the item's payload.
     *
     * @param SyncReviewItem[] $items
     *
     * @return array<int, array{anime: ?Anime, deletedFrom: string, stillPresentOn: list<string>}>
     */
    private function deletionDetails(array $items): array
    {
        $details = [];
        foreach ($items as $item) {
            if (!\in_array($item->kind, [SyncReviewItemKind::DeletedFromSource, SyncReviewItemKind::DeletionConflict], true)) {
                continue;
            }

            $id = $item->id ?? throw new \LogicException('SyncReviewItem id must be set after persisting');
            $animeId = $item->payload['anime_id'] ?? null;

            /** @var list<string> $stillPresentOn */
            $stillPresentOn = $item->payload['still_present_on'] ?? [];

            $details[$id] = [
                'anime' => \is_int($animeId) ? ($this->animeRepository->findByIds([$animeId])[$animeId] ?? null) : null,
                'deletedFrom' => (string) ($item->payload['deleted_from'] ?? ''),
                'stillPresentOn' => $stillPresentOn,
            ];
        }

        return $details;
    }

    /**
     * Display data for NeedsCorrection items (issue #382): the affected Anime plus the
     * per-participant candidates and the engine's own best-effort winner, read straight from the
     * item's payload — see {@see SyncConvergenceService} "flagConflict()".
     *
     * @param SyncReviewItem[] $items
     *
     * @return array<int, array{anime: ?Anime, candidates: list<array<string, mixed>>, winnerStatus: ?string, winnerWatchedEpisodes: ?int}>
     */
    private function needsCorrectionDetails(array $items): array
    {
        $details = [];
        foreach ($items as $item) {
            if ($item->kind !== SyncReviewItemKind::NeedsCorrection) {
                continue;
            }

            $id = $item->id ?? throw new \LogicException('SyncReviewItem id must be set after persisting');
            $animeId = $item->payload['anime_id'] ?? null;
            $winnerWatchedEpisodes = $item->payload['winner_watched_episodes'] ?? null;

            /** @var list<array<string, mixed>> $candidates */
            $candidates = $item->payload['candidates'] ?? [];

            $details[$id] = [
                'anime' => \is_int($animeId) ? ($this->animeRepository->findByIds([$animeId])[$animeId] ?? null) : null,
                'candidates' => $candidates,
                'winnerStatus' => \is_string($item->payload['winner_status'] ?? null) ? $item->payload['winner_status'] : null,
                'winnerWatchedEpisodes' => \is_int($winnerWatchedEpisodes) ? $winnerWatchedEpisodes : null,
            ];
        }

        return $details;
    }

    /**
     * Resolves the participant_id the user picked in the review form against the item's own
     * payload candidates — never a client-supplied projection directly — and forwards it to
     * {@see SyncConvergenceService::applyManualResolution()}, the same engine entry point a
     * clean reconcile() winner goes through.
     *
     * applyManualResolution() can come back false when the chosen candidate itself violates a
     * local invariant (Completed while not yet released) — it is rejected rather than applied,
     * see that method's docblock. Throwing here, same as the other invalid-input cases below,
     * keeps {@see resolve()} from ever marking the item resolved over a pick that never actually
     * took effect.
     */
    private function applyChosenCandidate(SyncReviewItem $item, Request $request): void
    {
        $animeId = $item->payload['anime_id'] ?? null;
        $anime = \is_int($animeId) ? ($this->animeRepository->findByIds([$animeId])[$animeId] ?? null) : null;
        if ($anime === null) {
            throw new BadRequestHttpException('Unknown anime for this review item.');
        }

        $participantId = (string) $request->request->get('participant_id', '');

        /** @var list<array<string, mixed>> $candidates */
        $candidates = $item->payload['candidates'] ?? [];
        foreach ($candidates as $candidate) {
            if (($candidate['participant_id'] ?? null) !== $participantId) {
                continue;
            }

            $status = WatchStatus::tryFrom((string) ($candidate['status'] ?? ''));
            if ($status === null) {
                throw new BadRequestHttpException('Unknown watch status for the chosen candidate.');
            }

            $watchedEpisodes = $candidate['watched_episodes'] ?? null;
            $chosen = new SyncProjection($status, \is_int($watchedEpisodes) ? $watchedEpisodes : null);

            if (!$this->syncConvergenceService->applyManualResolution($anime, $chosen, $this->entityManager)) {
                throw new BadRequestHttpException('Chosen candidate violates a local invariant and was not applied.');
            }

            return;
        }

        throw new BadRequestHttpException('Unknown participant chosen.');
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
