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

use App\Controller\DownloadUnlinkController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Download;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\DownloadRepository;
use App\Service\Download\DownloadFolderPointer;
use App\Service\Download\DownloadUnlinkService;
use App\Service\Download\DownloadViewFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class DownloadUnlinkControllerTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private EntityManager $entityManager;
    private DownloadRepository $repository;
    private DownloadUnlinkService $unlinker;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->repository = new DownloadRepository($this->entityManager);
        $this->unlinker = new DownloadUnlinkService(new DownloadFolderPointer(), $this->entityManager);
    }

    private function persistAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Anime A')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function createController(
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?Environment $twig = null,
    ): DownloadUnlinkController {
        return new DownloadUnlinkController(
            $this->repository,
            $this->unlinker,
            new DownloadViewFactory(),
            $csrfTokenManager ?? $this->createStub(CsrfTokenManagerInterface::class),
            $twig ?? $this->createStub(Environment::class),
        );
    }

    /**
     * Mirrors what a real CsrfTokenManager does: valid only for the exact `download_unlink_<id>`
     * id and value this row's form would have been rendered with. A stub that returns `true`
     * unconditionally (as this test previously did) would stay green even if the controller read
     * the token under the wrong id or field — see DownloadUnlinkController::unlink() (issue #857
     * review).
     */
    private function csrfTokenManagerValidFor(int $downloadId, string $value = 'token'): CsrfTokenManagerInterface
    {
        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token): bool => $token->getId() === 'download_unlink_'.$downloadId && $token->getValue() === $value,
        );

        return $csrfTokenManager;
    }

    public function testUnlinkDeletesTheRowAndRendersTheRefreshedBlockWithoutAnError(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/_downloads.html.twig', $this->callback(
                static fn (array $params): bool => $params['anime'] === ['id' => $anime->id]
                    && $params['downloads'] === []
                    && $params['error'] === null,
            ))
            ->willReturn('<section></section>');

        $controller = $this->createController($this->csrfTokenManagerValidFor((int) $download->id), $twig);
        $request = Request::create('/downloads/'.$download->id.'/unlink', 'POST', ['_token' => 'token']);

        $response = $controller->unlink($download, $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
    }

    public function testUnlinkRendersAConflictErrorAndKeepsTheRowWhenTheVersionChangedConcurrently(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        // Simulates a concurrent writer (the poller) touching the row after it was read for this request.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE downloads SET version = version + 1 WHERE id = ?',
            [$download->id],
        );

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('anime/_downloads.html.twig', $this->callback(
                static fn (array $params): bool => $params['error'] === 'anime_detail.downloads_unlink_conflict_error'
                    && \count($params['downloads']) === 1,
            ))
            ->willReturn('<section></section>');

        $controller = $this->createController($this->csrfTokenManagerValidFor((int) $download->id), $twig);
        $request = Request::create('/downloads/'.$download->id.'/unlink', 'POST', ['_token' => 'token']);

        $controller->unlink($download, $request);

        $this->entityManager->clear();
        $this->assertNotNull($this->repository->findByInfoHashAndAnime(self::HASH, (int) $anime->id));
    }

    /**
     * The token id is per-row (`download_unlink_<id>`), not a single id shared across the whole
     * form — a token that validates for one row's id must not be accepted for a different row
     * (issue #857 review).
     */
    public function testUnlinkRejectsATokenValidForADifferentDownloadRow(): void
    {
        $anime = $this->persistAnime();
        $downloadA = new Download('a'.str_repeat('0', 39), $anime);
        $this->repository->save($downloadA);
        $downloadB = new Download('b'.str_repeat('0', 39), $anime);
        $this->repository->save($downloadB);

        $controller = $this->createController($this->csrfTokenManagerValidFor((int) $downloadA->id));
        $request = Request::create('/downloads/'.$downloadB->id.'/unlink', 'POST', ['_token' => 'token']);

        $this->expectException(BadRequestHttpException::class);

        try {
            $controller->unlink($downloadB, $request);
        } finally {
            $this->assertNotNull($this->repository->findByInfoHashAndAnime($downloadB->getInfoHash(), (int) $anime->id));
        }
    }

    public function testUnlinkRejectsInvalidCsrfTokenAndNeverCallsTheService(): void
    {
        $anime = $this->persistAnime();
        $download = new Download(self::HASH, $anime);
        $this->repository->save($download);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/downloads/'.$download->id.'/unlink', 'POST', ['_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->unlink($download, $request);
    }
}
