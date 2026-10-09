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

namespace App\Tests\Unit\Service;

use App\Entity\Anime;
use App\Entity\Download;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidAnimeTypeChangeException;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\PendingSyncPush;
use App\Entity\SeriesAnime;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\IndexAnimeMessage;
use App\Message\SyncSeedMessage;
use App\Service\AnimeTypeChangeOutcome;
use App\Service\AnimeTypeChangeService;
use App\Tests\Support\BuildsAnimeDeleteService;
use App\Tests\Support\CreatesInMemoryEntityManager;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** In-place type change over a real entity manager (issue #1001). */
final class AnimeTypeChangeServiceTest extends TestCase
{
    use BuildsAnimeDeleteService;
    use CreatesInMemoryEntityManager;

    private EntityManager $entityManager;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createInMemoryEntityManager();
    }

    /** @param list<string> $activeSyncs */
    private function service(?\App\Service\JobLock\JobLockService $jobLock = null, array $activeSyncs = []): AnimeTypeChangeService
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new AnimeTypeChangeService(
            $this->entityManager,
            $this->newSyncRegistryWithActive($activeSyncs),
            $jobLock ?? $this->newJobLockService(),
            $bus,
        );
    }

    private function persistSeries(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Detective Conan')
            ->setDatePremiereAndEnd(new \DateTimeImmutable('1996-01-08'), new \DateTimeImmutable('2010-07-04'))
            ->setWatchStatus(WatchStatus::Watching);
        $anime->setEpisodesCount(1150);
        $anime->setWatchedEpisodes(1149);
        $anime->rememberExternalId(new PluginId('animedb-shikimori'), '235');
        $label = new Label('favorite');
        $this->entityManager->persist($label);
        $anime->addLabel($label);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->entityManager->persist(new Download('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $anime));
        $this->entityManager->persist(new PendingSyncPush($anime, 'animedb-shikimori'));
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE anime SET files_checked_at = 1700000000, date_add = 1600000000, date_update = 1600000000 WHERE id = ?', [$anime->id]);

        return $anime;
    }

    /** @return array<string, mixed> */
    private function row(?int $id): array
    {
        return $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM anime WHERE id = ?', [$id]) ?: [];
    }

    private function rows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    public function testSeriesToMovieKeepsTheRowAndEverythingAroundIt(): void
    {
        $anime = $this->persistSeries();
        $id = $anime->id;

        $outcome = $this->service()->change($anime, AnimeType::Movie, true);

        $this->assertSame(AnimeTypeChangeOutcome::Changed, $outcome);
        $this->assertSame(1, $this->rows('anime'));
        $row = $this->row($id);
        $this->assertSame('movie', $row['type']);
        $this->assertNull($row['episodes_count']);
        $this->assertNull($row['watched_episodes']);
        $this->assertSame((new \DateTimeImmutable('1996-01-08'))->getTimestamp(), $row['date_premiere']);
        $this->assertSame($row['date_premiere'], $row['date_end']);
        $this->assertSame(1600000000, $row['date_add']);
        $this->assertSame(1700000000, $row['files_checked_at']);
        $this->assertSame('Detective Conan', $row['title']);
        $this->assertSame(1, $this->rows('downloads'));
        $this->assertSame(1, $this->rows('pending_sync_push'));
        $this->assertSame(1, $this->rows('anime_external_id'));
        $this->assertSame(1, $this->rows('anime_labels'));

        $reloaded = $this->entityManager->find(Anime::class, $id);
        $this->assertInstanceOf(MovieAnime::class, $reloaded);
        $this->assertSame(WatchStatus::Watching, $reloaded->getWatchStatus());
    }

    public function testEmptyPremiereTakesTheFormerEnd(): void
    {
        $anime = $this->persistSeries();
        $anime->setDatePremiereAndEnd(null, new \DateTimeImmutable('2010-07-04'));
        $this->entityManager->flush();

        $this->service()->change($anime, AnimeType::Movie, true);

        $row = $this->row($anime->id);
        $expected = (new \DateTimeImmutable('2010-07-04'))->getTimestamp();
        $this->assertSame($expected, $row['date_premiere']);
        $this->assertSame($expected, $row['date_end']);
    }

    public function testChangeWithinSeriesLosesNothing(): void
    {
        $anime = $this->persistSeries();
        $before = $this->row($anime->id);

        $this->service()->change($anime, AnimeType::Ova, true);

        $after = $this->row($anime->id);
        $this->assertSame('ova', $after['type']);
        unset($before['type'], $after['type'], $before['date_update'], $after['date_update']);
        $this->assertSame($before, $after);

        $reloaded = $this->entityManager->find(Anime::class, $anime->id);
        $this->assertInstanceOf(SeriesAnime::class, $reloaded);
        $this->assertSame(1150, $reloaded->getEpisodesCount());
        $this->assertSame(1149, $reloaded->getWatchedEpisodes());
    }

    public function testLossyChangeWithoutConfirmationChangesNothing(): void
    {
        $anime = $this->persistSeries();
        $before = $this->row($anime->id);

        $outcome = $this->service()->change($anime, AnimeType::Movie, false);

        $this->assertSame(AnimeTypeChangeOutcome::LossNotConfirmed, $outcome);
        $this->assertSame($before, $this->row($anime->id));
        $this->assertSame([], $this->dispatched);
        $this->assertTrue($this->entityManager->contains($anime));
    }

    public function testChangeWithinSeriesNeedsNoConfirmation(): void
    {
        $anime = $this->persistSeries();

        $outcome = $this->service()->change($anime, AnimeType::Ova, false);

        $this->assertSame(AnimeTypeChangeOutcome::Changed, $outcome);
        $this->assertSame('ova', $this->row($anime->id)['type']);
    }

    public function testMovieToSeriesLeavesEpisodesEmpty(): void
    {
        $anime = $this->persistSeries();
        $this->service()->change($anime, AnimeType::Movie, true);
        $movie = $this->entityManager->find(Anime::class, $anime->id);
        $this->assertNotNull($movie);
        $this->entityManager->getConnection()->executeStatement('UPDATE anime SET episodes_count = 12, watched_episodes = 5 WHERE id = ?', [$anime->id]);
        $this->entityManager->clear();
        $movie = $this->entityManager->find(Anime::class, $anime->id);
        $this->assertNotNull($movie);

        $this->service()->change($movie, AnimeType::Tv, true);

        $row = $this->row($anime->id);
        $this->assertSame('tv', $row['type']);
        $this->assertNull($row['episodes_count']);
        $this->assertNull($row['watched_episodes']);
    }

    public function testChangeRaisesDateUpdateClearsTheEntityManagerAndQueuesReindex(): void
    {
        $anime = $this->persistSeries();

        $this->service()->change($anime, AnimeType::Ova, true);

        $this->assertGreaterThan(1600000000, $this->row($anime->id)['date_update']);
        $this->assertFalse($this->entityManager->contains($anime), 'the stale object must not outlive the change');
        $this->assertCount(1, $this->dispatched);
        $this->assertEquals(new IndexAnimeMessage((int) $anime->id), $this->dispatched[0]);
    }

    public function testChangeIsRefusedWhileASyncSeedLockIsHeld(): void
    {
        $anime = $this->persistSeries();
        $jobLock = $this->newJobLockService();
        $this->assertTrue($jobLock->acquire(SyncSeedMessage::jobKey('animedb-shikimori')));

        $outcome = $this->service($jobLock, ['animedb-shikimori'])->change($anime, AnimeType::Movie, true);

        $this->assertSame(AnimeTypeChangeOutcome::SyncRunning, $outcome);
        $row = $this->row($anime->id);
        $this->assertSame('tv', $row['type']);
        $this->assertSame(1150, $row['episodes_count']);
        $this->assertSame([], $this->dispatched);
    }

    public function testChangeToTheSameTypeIsRefusedAndWritesNothing(): void
    {
        $anime = $this->persistSeries();

        try {
            $this->service()->change($anime, AnimeType::Tv, true);
            $this->fail('Expected the same type to be refused.');
        } catch (InvalidAnimeTypeChangeException) {
            $this->assertSame([], $this->dispatched);
            $this->assertSame(1600000000, $this->row($anime->id)['date_update']);
        }
    }
}
