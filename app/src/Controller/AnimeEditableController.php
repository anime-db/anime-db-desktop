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

use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidEpisodeCountException;
use App\Entity\Exception\InvalidWatchStatusException;
use App\Entity\SeriesAnime;
use App\Entity\ValueObject\Exception\InvalidRatingException;
use App\Entity\ValueObject\Rating;
use App\Service\AnimeViewFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Inline editing of the anime detail card (issue #103): watch status, user rating, notes
 * and episode watch progress. Every action here (and the toggle in/out of edit mode) is
 * an HTMX partial that re-renders and swaps anime/_editable.html.twig, so there is no
 * separate "edit" screen or modal, per the issue's decomposition (#101 built the static
 * placeholder this replaces).
 *
 * The "Completed while still airing/announced" invariant is not re-implemented here: it
 * already lives on Anime::setWatchStatus()/SeriesAnime::setWatchedEpisodes(), including
 * the implicit path through episode progress. This controller only catches the resulting
 * exceptions and turns them into a translated error shown back in the edit form.
 *
 * Every mutation below goes through the *Manually() domain methods (Anime::changeWatchStatusManually(),
 * SeriesAnime::changeWatchedEpisodesManually()/watchNextEpisodeManually()), not the plain setters
 * directly (issue #371): this is what marks a change as user-driven for the sync push trigger,
 * as opposed to Anime::applyWatchProgress(), the sync-apply path, which never raises it.
 */
final class AnimeEditableController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly AnimeViewFactory $viewFactory,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/anime/{id}/editable', name: 'anime_editable_view', methods: ['GET'])]
    public function view(Anime $anime): Response
    {
        return $this->renderEditable($anime);
    }

    #[Route(
        '/anime/{id}/editable/{field}/edit',
        name: 'anime_editable_edit',
        requirements: ['field' => 'watch_status|user_rating|notes|watched_episodes'],
        methods: ['GET'],
    )]
    public function edit(Anime $anime, string $field): Response
    {
        return $this->renderEditable($anime, $field);
    }

    #[Route('/anime/{id}/editable/watch_status', name: 'anime_editable_update_watch_status', methods: ['POST'])]
    public function updateWatchStatus(Anime $anime, Request $request): Response
    {
        $this->assertValidCsrfToken('anime_editable_watch_status_'.$anime->id, $request);

        $status = WatchStatus::tryFrom((string) $request->request->get('watch_status', ''));
        if ($status === null) {
            return $this->renderEditable($anime, 'watch_status', 'anime_detail.error_watch_status_invalid');
        }

        try {
            $anime->changeWatchStatusManually($status);
        } catch (InvalidWatchStatusException) {
            return $this->renderEditable($anime, 'watch_status', 'anime_detail.error_watch_status_not_released');
        }

        $this->entityManager->flush();

        return $this->renderEditable($anime);
    }

    #[Route('/anime/{id}/editable/user_rating', name: 'anime_editable_update_user_rating', methods: ['POST'])]
    public function updateUserRating(Anime $anime, Request $request): Response
    {
        $this->assertValidCsrfToken('anime_editable_user_rating_'.$anime->id, $request);

        $value = trim((string) $request->request->get('user_rating', ''));

        try {
            $anime->setUserRating($value === '' ? null : new Rating((int) $value));
        } catch (InvalidRatingException) {
            return $this->renderEditable($anime, 'user_rating', 'anime_detail.error_user_rating_invalid');
        }

        $this->entityManager->flush();

        return $this->renderEditable($anime);
    }

    #[Route('/anime/{id}/editable/notes', name: 'anime_editable_update_notes', methods: ['POST'])]
    public function updateNotes(Anime $anime, Request $request): Response
    {
        $this->assertValidCsrfToken('anime_editable_notes_'.$anime->id, $request);

        $notes = trim((string) $request->request->get('notes', ''));
        $anime->setNotes($notes === '' ? null : $notes);
        $this->entityManager->flush();

        return $this->renderEditable($anime);
    }

    #[Route('/anime/{id}/editable/watched_episodes', name: 'anime_editable_update_watched_episodes', methods: ['POST'])]
    public function updateWatchedEpisodes(Anime $anime, Request $request): Response
    {
        $this->assertValidCsrfToken('anime_editable_watched_episodes_'.$anime->id, $request);

        if (!$anime instanceof SeriesAnime) {
            throw new BadRequestHttpException('watched_episodes is only editable for series anime.');
        }

        $raw = trim((string) $request->request->get('watched_episodes', ''));
        if (!is_numeric($raw)) {
            return $this->renderEditable($anime, 'watched_episodes', 'anime_detail.error_watched_episodes_invalid');
        }

        try {
            $anime->changeWatchedEpisodesManually((int) $raw);
        } catch (InvalidEpisodeCountException) {
            return $this->renderEditable($anime, 'watched_episodes', 'anime_detail.error_watched_episodes_invalid');
        }

        $this->entityManager->flush();

        return $this->renderEditable($anime);
    }

    #[Route(
        '/anime/{id}/editable/watched_episodes/increment',
        name: 'anime_editable_increment_watched_episodes',
        methods: ['POST'],
    )]
    public function incrementWatchedEpisodes(Anime $anime, Request $request): Response
    {
        $this->assertValidCsrfToken('anime_editable_increment_watched_episodes_'.$anime->id, $request);

        if (!$anime instanceof SeriesAnime) {
            throw new BadRequestHttpException('Episode progress is only available for series anime.');
        }

        try {
            $anime->watchNextEpisodeManually();
        } catch (InvalidEpisodeCountException) {
            return $this->renderEditable($anime, null, 'anime_detail.error_watched_episodes_invalid');
        }

        $this->entityManager->flush();

        return $this->renderEditable($anime);
    }

    private function renderEditable(Anime $anime, ?string $editing = null, ?string $error = null): Response
    {
        return new Response($this->twig->render('anime/_editable.html.twig', [
            'anime' => $this->viewFactory->serialize($anime),
            'editing' => $editing,
            'error' => $error,
            'watch_statuses' => array_column(WatchStatus::cases(), 'value'),
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
