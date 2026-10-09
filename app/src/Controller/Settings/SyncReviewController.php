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
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidAnimeTypeChangeException;
use App\Entity\SyncReviewItem;
use App\Repository\AnimeRepository;
use App\Repository\DownloadRepository;
use App\Service\AnimeDeleteFlash;
use App\Service\AnimeDeleteOutcome;
use App\Service\AnimeDeleteService;
use App\Service\AnimeTypeChangeOutcome;
use App\Service\AnimeTypeChangeService;
use App\Service\AnimeViewFactory;
use App\Service\Sync\DeletedFromSourceDetector;
use App\Service\Sync\SourceRemovalPlan;
use App\Service\Sync\SourceRemovalPlanner;
use App\Service\Sync\SyncConvergenceService;
use App\Service\Sync\SyncProjection;
use App\Service\Sync\SyncReviewService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
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
 *
 * DeletedFromSource / DeletionConflict (issue #864): resolving one of these means "keep the
 * catalog record even though the source no longer lists it". {@see resolve()} forwards that to
 * {@see DeletedFromSourceDetector::forgetListMembership()}, which removes the now-stale
 * AnimeSyncState snapshot row for the source plugin before flipping resolved_at — without it, the
 * next pull would see the same disappearance again and raise a duplicate review item.
 *
 * TypeMismatch (issue #1002): "keep" is the plain {@see resolve()}; {@see acceptType()} takes the
 * type the source reports through {@see AnimeTypeChangeService}, the same service as the entry
 * card's "Change type…" — a change that drops data (series ⇄ movie) shows that dialog's loss block
 * and needs its confirmation.
 */
final class SyncReviewController
{
    public function __construct(
        private readonly SyncReviewService $syncReview,
        private readonly AnimeRepository $animeRepository,
        private readonly SyncConvergenceService $syncConvergenceService,
        private readonly DeletedFromSourceDetector $deletedFromSourceDetector,
        private readonly AnimeDeleteService $animeDeleteService,
        private readonly AnimeDeleteFlash $animeDeleteFlash,
        private readonly SourceRemovalPlanner $sourceRemovalPlanner,
        private readonly DownloadRepository $downloads,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly AnimeTypeChangeService $typeChangeService,
        private readonly AnimeViewFactory $animeViewFactory,
        private readonly TranslatorInterface $translator,
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
            'typeMismatchDetails' => $this->typeMismatchDetails($items),
        ]));
    }

    #[Route('/settings/sync-review/{id}/resolve', name: 'settings_sync_review_resolve', methods: ['POST'])]
    public function resolve(SyncReviewItem $item, Request $request): Response
    {
        $this->assertValidCsrfToken('settings_sync_review_resolve_'.$item->id, $request);

        if ($item->kind === SyncReviewItemKind::NeedsCorrection) {
            $this->applyChosenCandidate($item, $request);
        } elseif (\in_array($item->kind, [SyncReviewItemKind::DeletedFromSource, SyncReviewItemKind::DeletionConflict], true)) {
            $this->forgetSyncListMembership($item);
        }

        $this->syncReview->resolve($item);

        if ($request->headers->get('HX-Request') === 'true') {
            return new Response('');
        }

        return new RedirectResponse($this->urlGenerator->generate('settings_sync_review_index'));
    }

    /**
     * "Accept the source's type" of a TypeMismatch item (issue #1002). The type comes from the item's
     * payload, never from the request. A change that drops data needs `confirm_loss`, as in the entry
     * card's dialog. The item is resolved only once the type is the source's; a refusal leaves it open.
     */
    #[Route('/settings/sync-review/{id}/accept-type', name: 'settings_sync_review_accept_type', methods: ['POST'])]
    public function acceptType(SyncReviewItem $item, Request $request): Response
    {
        $itemId = $item->id ?? throw new \LogicException('SyncReviewItem id must be set after persisting');
        $this->assertValidCsrfToken('settings_sync_review_accept_type_'.$itemId, $request);

        if ($item->kind !== SyncReviewItemKind::TypeMismatch) {
            throw new BadRequestHttpException('Only a type mismatch item can accept the type of the source.');
        }

        $targetType = AnimeType::tryFrom((string) ($item->payload['source_type'] ?? ''))
            ?? throw new BadRequestHttpException('Unknown source type.');
        $animeId = $item->payload['anime_id'] ?? null;
        $anime = \is_int($animeId) ? ($this->animeRepository->findByIds([$animeId])[$animeId] ?? null) : null;

        if ($anime === null || $anime->getType() === $targetType) {
            $this->syncReview->resolve($item);

            return $this->backToIndex();
        }

        try {
            $change = $anime->planTypeChange($targetType);
            if ($change->isLossy() && !$request->request->getBoolean('confirm_loss')) {
                $this->flash($request, 'danger', 'anime_type_change.flash_not_confirmed');
            } elseif ($this->typeChangeService->change($anime, $targetType) === AnimeTypeChangeOutcome::Changed) {
                // The service clears the entity manager, so the item is loaded again to be resolved.
                $fresh = $this->entityManager->find(SyncReviewItem::class, $itemId);
                if ($fresh !== null) {
                    $this->syncReview->resolve($fresh);
                }
                $this->flash($request, 'success', 'anime_type_change.flash_changed', ['%type%' => $this->translator->trans('anime_type.'.$targetType->value)]);
            } else {
                $this->flash($request, 'danger', 'anime_type_change.flash_sync_running');
            }
        } catch (InvalidAnimeTypeChangeException) {
            $this->flash($request, 'danger', 'anime_type_change.flash_invalid');
        }

        return $this->backToIndex();
    }

    /**
     * The second action of a DeletedFromSource / DeletionConflict item (issue #916): delete the
     * catalog record through the same {@see AnimeDeleteService} the entry page uses, with the same
     * refusals and tombstones. The service closes every item that pointed at the record, this one
     * included. A refusal leaves the item open. A record that is already gone just closes the item.
     */
    #[Route('/settings/sync-review/{id}/delete-anime', name: 'settings_sync_review_delete_anime', methods: ['POST'])]
    public function deleteAnime(SyncReviewItem $item, Request $request): Response
    {
        $this->assertValidCsrfToken('settings_sync_review_delete_anime_'.$item->id, $request);

        if (!\in_array($item->kind, [SyncReviewItemKind::DeletedFromSource, SyncReviewItemKind::DeletionConflict], true)) {
            throw new BadRequestHttpException('Only a source-side removal item can delete its record.');
        }

        $animeId = $item->payload['anime_id'] ?? null;
        $anime = \is_int($animeId) ? ($this->animeRepository->findByIds([$animeId])[$animeId] ?? null) : null;

        if ($anime === null) {
            $this->syncReview->resolve($item);
        } else {
            // The same targets the dialog showed, counted again now by the service; the source the entry
            // is already gone from is never one of them.
            $outcome = $this->animeDeleteService->delete(
                $anime,
                $request->request->getBoolean('remove_from_sources'),
                $this->deletedFromPluginIds($item),
            );
            $this->animeDeleteFlash->add($request, $outcome, $anime->getTitle());
            if ($outcome === AnimeDeleteOutcome::Deleted && !$item->isResolved()) {
                $this->syncReview->resolve($item);
            }
        }

        return new RedirectResponse($this->urlGenerator->generate('settings_sync_review_index'));
    }

    private function backToIndex(): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('settings_sync_review_index'));
    }

    /** @param array<string, string> $parameters */
    private function flash(Request $request, string $type, string $key, array $parameters = []): void
    {
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);

        $session->getFlashBag()->add($type, ['text' => $this->translator->trans($key, $parameters), 'link_url' => null, 'link_label' => null]);
    }

    /**
     * Display data for TypeMismatch items (issue #1002): the source and its type from the payload, and
     * the record's type **as it is now** (read here, not a snapshot taken when the item was raised).
     * `typeChange` has the shape the "Change type…" dialog takes and is null when the record is gone or
     * the domain refuses the change.
     *
     * @param SyncReviewItem[] $items
     *
     * @return array<int, array{anime: ?Anime, pluginId: string, sourceType: string, typeChange: ?array<string, mixed>}>
     */
    private function typeMismatchDetails(array $items): array
    {
        $details = [];
        foreach ($items as $item) {
            if ($item->kind !== SyncReviewItemKind::TypeMismatch) {
                continue;
            }

            $id = $item->id ?? throw new \LogicException('SyncReviewItem id must be set after persisting');
            $animeId = $item->payload['anime_id'] ?? null;
            $anime = \is_int($animeId) ? ($this->animeRepository->findByIds([$animeId])[$animeId] ?? null) : null;
            $sourceType = AnimeType::tryFrom((string) ($item->payload['source_type'] ?? ''));

            $details[$id] = [
                'anime' => $anime,
                'pluginId' => (string) ($item->payload['plugin_id'] ?? ''),
                'sourceType' => $sourceType !== null ? $sourceType->value : '',
                'typeChange' => $anime !== null && $sourceType !== null ? $this->animeViewFactory->serializeTypeChange($anime, $sourceType) : null,
            ];
        }

        return $details;
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
     * @return array<int, array{anime: ?Anime, deletedFrom: string, stillPresentOn: list<string>, hasStorage: bool, hasFinishedDownloads: bool, sourceRemoval: SourceRemovalPlan}>
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

            $anime = \is_int($animeId) ? ($this->animeRepository->findByIds([$animeId])[$animeId] ?? null) : null;

            $details[$id] = [
                'anime' => $anime,
                'deletedFrom' => (string) ($item->payload['deleted_from'] ?? ''),
                'stillPresentOn' => $stillPresentOn,
                // What the delete confirmation warns about, see anime/_delete_confirm.html.twig.
                'hasStorage' => $anime?->getStorage() !== null,
                'hasFinishedDownloads' => $anime !== null && $this->downloads->hasFinishedForAnime($anime->id ?? 0),
                // Counted now, not taken from still_present_on: a plugin may have been switched off
                // since the detection, and the source the entry is deleted from is no target.
                'sourceRemoval' => $anime !== null ? $this->sourceRemovalPlanner->plan($anime, $this->deletedFromPluginIds($item)) : new SourceRemovalPlan(),
            ];
        }

        return $details;
    }

    /** @return list<string> */
    private function deletedFromPluginIds(SyncReviewItem $item): array
    {
        $deletedFrom = $item->payload['deleted_from'] ?? null;

        return \is_string($deletedFrom) && $deletedFrom !== '' ? [$deletedFrom] : [];
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

    /**
     * Resolving "keep" for a DeletedFromSource/DeletionConflict item (issue #864) means the source
     * no longer lists this title but the user wants to keep the catalog record: the stale
     * AnimeSyncState snapshot row for the source plugin is removed via {@see
     * DeletedFromSourceDetector::forgetListMembership()} so a later pull does not see confirmed
     * list membership that no longer exists and flag the same disappearance again.
     *
     * A malformed payload, an already-missing snapshot row, or the catalog record itself having
     * been removed in the meantime are all silently accepted — {@see resolve()} still goes on to
     * flip resolved_at in every case.
     */
    private function forgetSyncListMembership(SyncReviewItem $item): void
    {
        $animeId = $item->payload['anime_id'] ?? null;
        $pluginId = $item->payload['deleted_from'] ?? null;
        if (!\is_int($animeId) || !\is_string($pluginId) || $pluginId === '') {
            return;
        }

        $anime = $this->animeRepository->findByIds([$animeId])[$animeId] ?? null;
        if ($anime === null) {
            return;
        }

        $this->deletedFromSourceDetector->forgetListMembership($anime, $pluginId);
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
