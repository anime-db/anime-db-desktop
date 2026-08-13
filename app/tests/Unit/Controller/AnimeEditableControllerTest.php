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

namespace App\Tests\Unit\Controller;

use App\Controller\AnimeEditableController;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\TvAnime;
use App\Entity\ValueObject\Rating;
use App\Service\AnimeViewFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class AnimeEditableControllerTest extends TestCase
{
    private function createController(
        ?EntityManagerInterface $entityManager = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?Environment $twig = null,
    ): AnimeEditableController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new AnimeEditableController(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $csrfTokenManager,
            new AnimeViewFactory($requestStack),
            $twig ?? $this->createStub(Environment::class),
        );
    }

    private function rejectingCsrfManager(): CsrfTokenManagerInterface
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        return $csrf;
    }

    public function testViewRendersFragmentInReadOnlyMode(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/_editable.html.twig', $this->callback(
                static fn (array $params): bool => $params['editing'] === null && $params['error'] === null,
            ))
            ->willReturn('<section></section>');

        $response = $this->createController(twig: $twig)->view($anime);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEditRendersFragmentWithFieldInEditMode(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/_editable.html.twig', $this->callback(
                static fn (array $params): bool => $params['editing'] === 'notes',
            ))
            ->willReturn('<section></section>');

        $this->createController(twig: $twig)->edit($anime, 'notes');
    }

    public function testUpdateWatchStatusPersistsValidStatus(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Plan);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $request = Request::create('/anime/1/editable/watch_status', 'POST', ['watch_status' => 'watching', '_token' => 'token']);

        $this->createController(entityManager: $entityManager)->updateWatchStatus($anime, $request);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testUpdateWatchStatusRejectsCompletedWhileNotReleased(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/_editable.html.twig', $this->callback(
                static fn (array $params): bool => $params['editing'] === 'watch_status'
                    && $params['error'] === 'anime_detail.error_watch_status_not_released',
            ))
            ->willReturn('<section></section>');

        $request = Request::create('/anime/1/editable/watch_status', 'POST', ['watch_status' => 'completed', '_token' => 'token']);

        $this->createController(entityManager: $entityManager, twig: $twig)->updateWatchStatus($anime, $request);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testUpdateWatchStatusRejectsUnknownValue(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $request = Request::create('/anime/1/editable/watch_status', 'POST', ['watch_status' => 'bogus', '_token' => 'token']);

        $this->createController(entityManager: $entityManager)->updateWatchStatus($anime, $request);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testUpdateWatchStatusRejectsInvalidCsrfToken(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $request = Request::create('/anime/1/editable/watch_status', 'POST', ['watch_status' => 'plan', '_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $this->createController(csrfTokenManager: $this->rejectingCsrfManager())->updateWatchStatus($anime, $request);
    }

    public function testUpdateUserRatingSetsRating(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $request = Request::create('/anime/1/editable/user_rating', 'POST', ['user_rating' => '4', '_token' => 'token']);

        $this->createController(entityManager: $entityManager)->updateUserRating($anime, $request);

        $this->assertSame(4, $anime->getUserRating()?->value);
    }

    public function testUpdateUserRatingClearsRatingWhenEmpty(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching)->setUserRating(new Rating(3));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $request = Request::create('/anime/1/editable/user_rating', 'POST', ['user_rating' => '', '_token' => 'token']);

        $this->createController(entityManager: $entityManager)->updateUserRating($anime, $request);

        $this->assertNull($anime->getUserRating());
    }

    public function testUpdateUserRatingRejectsOutOfRangeValue(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $request = Request::create('/anime/1/editable/user_rating', 'POST', ['user_rating' => '9', '_token' => 'token']);

        $this->createController(entityManager: $entityManager)->updateUserRating($anime, $request);

        $this->assertNull($anime->getUserRating());
    }

    public function testUpdateUserRatingRejectsInvalidCsrfToken(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $request = Request::create('/anime/1/editable/user_rating', 'POST', ['user_rating' => '4', '_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $this->createController(csrfTokenManager: $this->rejectingCsrfManager())->updateUserRating($anime, $request);
    }

    public function testUpdateNotesSetsTrimmedValue(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $request = Request::create('/anime/1/editable/notes', 'POST', ['notes' => '  Rewatch later.  ', '_token' => 'token']);

        $this->createController(entityManager: $entityManager)->updateNotes($anime, $request);

        $this->assertSame('Rewatch later.', $anime->getNotes());
    }

    public function testUpdateNotesClearsToNullWhenBlank(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching)->setNotes('old note');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $request = Request::create('/anime/1/editable/notes', 'POST', ['notes' => '   ', '_token' => 'token']);

        $this->createController(entityManager: $entityManager)->updateNotes($anime, $request);

        $this->assertNull($anime->getNotes());
    }

    public function testUpdateNotesRejectsInvalidCsrfToken(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $request = Request::create('/anime/1/editable/notes', 'POST', ['notes' => 'x', '_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $this->createController(csrfTokenManager: $this->rejectingCsrfManager())->updateNotes($anime, $request);
    }

    public function testUpdateWatchedEpisodesSetsValue(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(28);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $request = Request::create('/anime/1/editable/watched_episodes', 'POST', ['watched_episodes' => '10', '_token' => 'token']);

        $this->createController(entityManager: $entityManager)->updateWatchedEpisodes($anime, $request);

        $this->assertSame(10, $anime->getWatchedEpisodes());
    }

    public function testUpdateWatchedEpisodesRejectsOutOfRangeValue(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(12);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $request = Request::create('/anime/1/editable/watched_episodes', 'POST', ['watched_episodes' => '99', '_token' => 'token']);

        $this->createController(entityManager: $entityManager)->updateWatchedEpisodes($anime, $request);

        $this->assertNull($anime->getWatchedEpisodes());
    }

    public function testUpdateWatchedEpisodesRejectsNonNumericValue(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(12);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $request = Request::create('/anime/1/editable/watched_episodes', 'POST', ['watched_episodes' => 'abc', '_token' => 'token']);

        $this->createController(entityManager: $entityManager)->updateWatchedEpisodes($anime, $request);

        $this->assertNull($anime->getWatchedEpisodes());
    }

    public function testUpdateWatchedEpisodesRejectsForMovie(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('A Silent Voice')->setWatchStatus(WatchStatus::Plan);

        $request = Request::create('/anime/1/editable/watched_episodes', 'POST', ['watched_episodes' => '1', '_token' => 'token']);

        $this->expectException(BadRequestHttpException::class);
        $this->createController()->updateWatchedEpisodes($anime, $request);
    }

    public function testUpdateWatchedEpisodesRejectsInvalidCsrfToken(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $request = Request::create('/anime/1/editable/watched_episodes', 'POST', ['watched_episodes' => '1', '_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $this->createController(csrfTokenManager: $this->rejectingCsrfManager())->updateWatchedEpisodes($anime, $request);
    }

    public function testIncrementWatchedEpisodesIncrementsByOneWithoutForcingCompleted(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(12);
        $anime->setWatchedEpisodes(11);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $request = Request::create('/anime/1/editable/watched_episodes/increment', 'POST', ['_token' => 'token']);

        $this->createController(entityManager: $entityManager)->incrementWatchedEpisodes($anime, $request);

        // Not released (no premiere/end dates set), so the last-episode increment must not
        // silently flip watch_status to Completed (issue #103 acceptance criterion).
        $this->assertSame(12, $anime->getWatchedEpisodes());
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testIncrementWatchedEpisodesRejectsForMovie(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('A Silent Voice')->setWatchStatus(WatchStatus::Plan);

        $request = Request::create('/anime/1/editable/watched_episodes/increment', 'POST', ['_token' => 'token']);

        $this->expectException(BadRequestHttpException::class);
        $this->createController()->incrementWatchedEpisodes($anime, $request);
    }

    public function testIncrementWatchedEpisodesRejectsInvalidCsrfToken(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Frieren')->setWatchStatus(WatchStatus::Watching);

        $request = Request::create('/anime/1/editable/watched_episodes/increment', 'POST', ['_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $this->createController(csrfTokenManager: $this->rejectingCsrfManager())->incrementWatchedEpisodes($anime, $request);
    }
}
