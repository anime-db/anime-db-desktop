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

use App\Controller\AnimeTypeChangeController;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Message\SyncSeedMessage;
use App\Service\AnimeTypeChangeService;
use App\Tests\Support\BuildsAnimeDeleteService;
use App\Tests\Support\CreatesInMemoryEntityManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Translator;

/** The card's POST anime_change_type (issue #1001) over a real {@see AnimeTypeChangeService}. */
final class AnimeTypeChangeControllerTest extends TestCase
{
    use BuildsAnimeDeleteService;
    use CreatesInMemoryEntityManager;

    private EntityManager $entityManager;
    private Session $session;

    protected function setUp(): void
    {
        $this->entityManager = $this->createInMemoryEntityManager();
        $this->session = new Session(new MockArraySessionStorage());
    }

    private function persistSeries(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setDatePremiereAndEnd(new \DateTimeImmutable('1998-04-01'), new \DateTimeImmutable('1998-09-30'))->setWatchStatus(WatchStatus::Plan);
        $anime->setEpisodesCount(26);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    /** @param array<string, string> $fields */
    private function request(array $fields, bool $validToken = true): Request
    {
        $request = new Request([], ['_token' => $validToken ? 'ok' : 'bad'] + $fields);
        $request->setSession($this->session);

        return $request;
    }

    /** @param list<string> $activeSyncs */
    private function controller(?\App\Service\JobLock\JobLockService $jobLock = null, array $activeSyncs = []): AnimeTypeChangeController
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getValue() === 'ok');

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $name, array $params = []): string => '/'.$name.'/'.implode(',', $params));

        return new AnimeTypeChangeController(
            new AnimeTypeChangeService($this->entityManager, $this->newSyncRegistryWithActive($activeSyncs), $jobLock ?? $this->newJobLockService(), $bus),
            $csrf,
            $urls,
            new Translator('en'),
        );
    }

    private function type(?int $id): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne('SELECT type FROM anime WHERE id = ?', [$id]);
    }

    /** @return list<string> */
    private function flashKinds(): array
    {
        return array_keys($this->session->getFlashBag()->all());
    }

    public function testALosslessChangeNeedsNoConfirmation(): void
    {
        $anime = $this->persistSeries();

        $response = $this->controller()->change($anime, $this->request(['type' => 'ova']));

        $this->assertSame('/anime_show/'.$anime->id, $response->headers->get('Location'));
        $this->assertSame('ova', $this->type($anime->id));
    }

    public function testASeriesBecomesAMovieOnlyWithTheLossConfirmed(): void
    {
        $anime = $this->persistSeries();

        $this->controller()->change($anime, $this->request(['type' => 'movie']));

        $this->assertSame('tv', $this->type($anime->id), 'without the box ticked nothing changes');
        $this->assertSame(['danger'], $this->flashKinds());
        $this->assertSame(26, (int) $this->entityManager->getConnection()->fetchOne('SELECT episodes_count FROM anime'));

        $this->controller()->change($anime, $this->request(['type' => 'movie', 'confirm_loss' => '1']));

        $this->assertSame('movie', $this->type($anime->id));
    }

    public function testARunningSyncRefusesTheChange(): void
    {
        $anime = $this->persistSeries();
        $jobLock = $this->newJobLockService();
        $jobLock->acquire(SyncSeedMessage::jobKey('animedb-shikimori'));

        $this->controller($jobLock, ['animedb-shikimori'])->change($anime, $this->request(['type' => 'ova']));

        $this->assertSame('tv', $this->type($anime->id));
        $this->assertSame(['danger'], $this->flashKinds());
    }

    public function testTheSameTypeIsReportedAndChangesNothing(): void
    {
        $anime = $this->persistSeries();

        $this->controller()->change($anime, $this->request(['type' => 'tv']));

        $this->assertSame('tv', $this->type($anime->id));
        $this->assertSame(['danger'], $this->flashKinds());
    }

    public function testAnInvalidTokenOrUnknownTypeIsRejected(): void
    {
        $anime = $this->persistSeries();

        foreach ([$this->request(['type' => 'ova'], false), $this->request(['type' => 'nonsense'])] as $request) {
            try {
                $this->controller()->change($anime, $request);
                $this->fail('The request must be rejected.');
            } catch (BadRequestHttpException) {
            }
        }

        $this->assertSame('tv', $this->type($anime->id));
    }
}
