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

use App\Controller\DownloadAdoptController;
use App\Entity\Anime;
use App\Entity\Download;
use App\Entity\Enum\DownloadStatus;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Service\Download\DownloadActionOutcome;
use App\Service\Download\DownloadAdoptionRefusedException;
use App\Service\Download\DownloadOrphanAdopter;
use App\Service\Download\DownloadStorageConflictAdopter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class DownloadAdoptControllerTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function makeAnime(int $id): TvAnime
    {
        $anime = new TvAnime();
        (new \ReflectionProperty(Anime::class, 'id'))->setValue($anime, $id);

        return $anime;
    }

    private function controller(DownloadOrphanAdopter $adopter, bool $csrfValid, Environment $twig, ?DownloadStorageConflictAdopter $conflictAdopter = null): DownloadAdoptController
    {
        $animeRepository = $this->createStub(AnimeRepository::class);
        $animeRepository->method('findByIds')->willReturn([5 => $this->makeAnime(5)]);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $name, array $params = []): string => '/'.$name.($params === [] ? '' : '?'.http_build_query($params)));

        return new DownloadAdoptController($adopter, $animeRepository, $csrf, $urlGenerator, $twig, $conflictAdopter ?? $this->createStub(DownloadStorageConflictAdopter::class));
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(array $fields): Request
    {
        return Request::create('/downloads/orphan/'.self::HASH.'/adopt', 'POST', $fields);
    }

    public function testInvalidCsrfTokenIsABadRequestAndNothingIsAdopted(): void
    {
        $adopter = $this->createMock(DownloadOrphanAdopter::class);
        $adopter->expects($this->never())->method('adopt');

        $this->expectException(BadRequestHttpException::class);

        $this->controller($adopter, false, $this->createStub(Environment::class))->adopt(self::HASH, $this->post(['_token' => 'x', 'anime' => '5']));
    }

    public function testSuccessRedirectsToDownloads(): void
    {
        $adopter = $this->createMock(DownloadOrphanAdopter::class);
        $adopter->expects($this->once())->method('adopt')->with(self::HASH, 5);

        $response = $this->controller($adopter, true, $this->createStub(Environment::class))->adopt(self::HASH, $this->post(['_token' => 'x', 'anime' => '5']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/downloads_index', $response->getTargetUrl());
    }

    public function testRefusalRendersThePickerWithTheErrorAndTheSelectedAnime(): void
    {
        $adopter = $this->createStub(DownloadOrphanAdopter::class);
        $adopter->method('adopt')->willThrowException(new DownloadAdoptionRefusedException('download_adopt.error_already_linked', ['%id%' => '7']));
        $adopter->method('findTorrent')->willReturn(['name' => 'Some torrent']);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')->with('downloads/adopt.html.twig', $this->callback(
            static fn (array $params): bool => $params['error'] === 'download_adopt.error_already_linked'
                && $params['errorParams'] === ['%id%' => '7']
                && $params['selectedAnime'] instanceof Anime
                && $params['selectedAnime']->id === 5
                && $params['torrentName'] === 'Some torrent'
                && $params['infoHash'] === self::HASH,
        ))->willReturn('<html></html>');

        $response = $this->controller($adopter, true, $twig)->adopt(self::HASH, $this->post(['_token' => 'x', 'anime' => '5']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testGetRendersThePickerWithTheTorrentName(): void
    {
        $adopter = $this->createMock(DownloadOrphanAdopter::class);
        $adopter->expects($this->never())->method('adopt');
        $adopter->method('findTorrent')->willReturn(['name' => 'Some torrent']);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')->with('downloads/adopt.html.twig', $this->callback(
            static fn (array $params): bool => $params['error'] === null && $params['torrentName'] === 'Some torrent' && $params['selectedAnime'] === null,
        ))->willReturn('<html></html>');

        $this->controller($adopter, true, $twig)->adopt(self::HASH, Request::create('/downloads/orphan/'.self::HASH.'/adopt'));
    }

    public function testMissingAnimeSelectionReRendersWithTheRequiredError(): void
    {
        $adopter = $this->createMock(DownloadOrphanAdopter::class);
        $adopter->expects($this->never())->method('adopt');
        $adopter->method('findTorrent')->willReturn(['name' => 'Some torrent']);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')->with('downloads/adopt.html.twig', $this->callback(
            static fn (array $params): bool => $params['error'] === 'download_new.error_anime_required',
        ))->willReturn('<html></html>');

        $this->controller($adopter, true, $twig)->adopt(self::HASH, $this->post(['_token' => 'x']));
    }

    private function conflictRow(string $reason = 'storage_conflict'): Download
    {
        $download = new Download(self::HASH, $this->makeAnime(1));
        $download->markFailed($reason);
        (new \ReflectionProperty(Download::class, 'id'))->setValue($download, 9);

        return $download;
    }

    public function testRowPickerPrefillsTheFolderOwnerAndCarriesVersionAndStatus(): void
    {
        $owner = $this->makeAnime(5);
        $conflictAdopter = $this->createStub(DownloadStorageConflictAdopter::class);
        $conflictAdopter->method('findFolderOwner')->willReturn($owner);
        $adopter = $this->createStub(DownloadOrphanAdopter::class);
        $adopter->method('findTorrent')->willReturn(['name' => 'Some torrent']);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')->with('downloads/adopt.html.twig', $this->callback(
            static fn (array $params): bool => $params['selectedAnime'] === $owner
                && $params['error'] === null
                && $params['form']['action'] === '/download_adopt?id=9'
                && $params['form']['tokenId'] === 'download_adopt_9'
                && $params['form']['hidden'] === ['version' => '3', 'status' => 'failed'],
        ))->willReturn('<html></html>');

        $this->controller($adopter, true, $twig, $conflictAdopter)->adoptRow($this->conflictRow(), Request::create('/downloads/9/adopt', 'GET', ['version' => '3', 'status' => 'failed']));
    }

    public function testRowPickerHasAnEmptyFieldWhenThereIsNoOwner(): void
    {
        $conflictAdopter = $this->createStub(DownloadStorageConflictAdopter::class);
        $conflictAdopter->method('findFolderOwner')->willReturn(null);
        $adopter = $this->createStub(DownloadOrphanAdopter::class);
        $adopter->method('findTorrent')->willReturn(['name' => 'Some torrent']);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')->with('downloads/adopt.html.twig', $this->callback(
            static fn (array $params): bool => $params['selectedAnime'] === null,
        ))->willReturn('<html></html>');

        $this->controller($adopter, true, $twig, $conflictAdopter)->adoptRow($this->conflictRow(), Request::create('/downloads/9/adopt'));
    }

    public function testRowPickerIsRefusedForARowThatIsNotAStorageConflict(): void
    {
        $conflictAdopter = $this->createMock(DownloadStorageConflictAdopter::class);
        $conflictAdopter->expects($this->never())->method('findFolderOwner');

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')->with('downloads/adopt.html.twig', $this->callback(
            static fn (array $params): bool => $params['error'] === 'downloads.action_error_refused',
        ))->willReturn('<html></html>');

        $this->controller($this->createStub(DownloadOrphanAdopter::class), true, $twig, $conflictAdopter)->adoptRow($this->conflictRow('disk_space'), Request::create('/downloads/9/adopt'));
    }

    public function testRowPostWithAnInvalidCsrfTokenIsABadRequest(): void
    {
        $conflictAdopter = $this->createMock(DownloadStorageConflictAdopter::class);
        $conflictAdopter->expects($this->never())->method('adopt');

        $this->expectException(BadRequestHttpException::class);

        $this->controller($this->createStub(DownloadOrphanAdopter::class), false, $this->createStub(Environment::class), $conflictAdopter)
            ->adoptRow($this->conflictRow(), Request::create('/downloads/9/adopt', 'POST', ['_token' => 'x', 'anime' => '5', 'version' => '3', 'status' => 'failed']));
    }

    public function testRowPostWithoutVersionAndStatusReportsAConflictAndChangesNothing(): void
    {
        $conflictAdopter = $this->createMock(DownloadStorageConflictAdopter::class);
        $conflictAdopter->expects($this->never())->method('adopt');
        $adopter = $this->createStub(DownloadOrphanAdopter::class);
        $adopter->method('findTorrent')->willReturn(['name' => 'Some torrent']);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())->method('render')->with('downloads/adopt.html.twig', $this->callback(
            static fn (array $params): bool => $params['error'] === 'downloads.action_error_conflict',
        ))->willReturn('<html></html>');

        $this->controller($adopter, true, $twig, $conflictAdopter)->adoptRow($this->conflictRow(), Request::create('/downloads/9/adopt', 'POST', ['_token' => 'x', 'anime' => '5']));
    }

    public function testRowPostSuccessRedirectsToDownloadsWithTheFormsExpectedState(): void
    {
        $row = $this->conflictRow();
        $conflictAdopter = $this->createMock(DownloadStorageConflictAdopter::class);
        $conflictAdopter->expects($this->once())->method('adopt')
            ->with($row, $this->callback(static fn (Anime $a): bool => $a->id === 5), 3, DownloadStatus::Failed)
            ->willReturn(DownloadActionOutcome::Success);

        $response = $this->controller($this->createStub(DownloadOrphanAdopter::class), true, $this->createStub(Environment::class), $conflictAdopter)
            ->adoptRow($row, Request::create('/downloads/9/adopt', 'POST', ['_token' => 'x', 'anime' => '5', 'version' => '3', 'status' => 'failed']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/downloads_index', $response->getTargetUrl());
    }

    public function testRowPostConflictAndRefusalReRenderTheirErrors(): void
    {
        $adopter = $this->createStub(DownloadOrphanAdopter::class);
        $adopter->method('findTorrent')->willReturn(['name' => 'Some torrent']);
        $errors = [];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $template, array $params) use (&$errors): string {
            $errors[] = $params['error'];

            return '';
        });

        $conflictAdopter = $this->createStub(DownloadStorageConflictAdopter::class);
        $conflictAdopter->method('adopt')->willReturnOnConsecutiveCalls(DownloadActionOutcome::Conflict, $this->throwException(new DownloadAdoptionRefusedException('download_adopt.error_storage_mismatch', ['%storage%' => 'Main'])));
        $controller = $this->controller($adopter, true, $twig, $conflictAdopter);
        $post = ['_token' => 'x', 'anime' => '5', 'version' => '3', 'status' => 'failed'];

        $controller->adoptRow($this->conflictRow(), Request::create('/downloads/9/adopt', 'POST', $post));
        $controller->adoptRow($this->conflictRow(), Request::create('/downloads/9/adopt', 'POST', $post));

        $this->assertSame(['downloads.action_error_conflict', 'download_adopt.error_storage_mismatch'], $errors);
    }
}
