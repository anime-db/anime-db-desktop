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

namespace App\Tests\Unit\MessageHandler;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\AnimeImage;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Message\DownloadAnimeMediaMessage;
use App\MessageHandler\DownloadAnimeMediaMessageHandler;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class DownloadAnimeMediaMessageHandlerTest extends TestCase
{
    private EntityManager $entityManager;

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

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    private function persistAnime(): MovieAnime
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    public function testSetsTheCoverWhenIsCoverIsTrue(): void
    {
        $anime = $this->persistAnime();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        // A mock rather than a stub, and deliberately so: with*() on a stub is silently ignored,
        // which would leave "the handler passes the message's own animeId and url through to the
        // downloader" — the whole reason the message carries a url at all — unverified.
        $downloader = $this->createMock(PluginMediaDownloaderInterface::class);
        $downloader->expects($this->once())
            ->method('download')
            ->with($animeId, 'https://example.test/cover.jpg')
            ->willReturn('abc123.webp');

        $handler = new DownloadAnimeMediaMessageHandler($this->entityManager, $downloader, new NullLogger());
        $handler(new DownloadAnimeMediaMessage($animeId, 'https://example.test/cover.jpg', true));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(MovieAnime::class, $animeId);
        $this->assertSame('abc123.webp', $reloaded?->getCover());
    }

    /**
     * Issue #916: the entry is deleted while its image is being downloaded. The handler must not
     * attach anything to it (a gallery row for a deleted entry would fail on the foreign key), and
     * the downloader is handed a check that answers false by then, so it writes no file.
     */
    public function testNothingIsAttachedWhenTheEntryIsDeletedWhileTheImageIsDownloading(): void
    {
        $anime = $this->persistAnime();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        $stillWantedAnswers = [];
        $downloader = $this->createMock(PluginMediaDownloaderInterface::class);
        $downloader->expects($this->once())
            ->method('download')
            ->willReturnCallback(function (int $id, string $url, ?\Closure $stillWanted) use (&$stillWantedAnswers): string {
                $this->assertNotNull($stillWanted);
                $stillWantedAnswers[] = $stillWanted();
                // Deleted by another process (the handler runs in the messenger consumer), so the
                // handler's own EntityManager still holds the entry as managed.
                $this->entityManager->getConnection()->executeStatement('DELETE FROM '.$this->entityManager->getClassMetadata(Anime::class)->getTableName().' WHERE id = ?', [$id]);
                $stillWantedAnswers[] = $stillWanted();

                return 'abc123.webp';
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $handler = new DownloadAnimeMediaMessageHandler($this->entityManager, $downloader, $logger);
        $handler(new DownloadAnimeMediaMessage($animeId, 'https://example.test/1.jpg', false));

        $this->assertSame([true, false], $stillWantedAnswers);
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->entityManager->getClassMetadata(Anime::class)->getTableName()));
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->entityManager->getClassMetadata(AnimeImage::class)->getTableName()));
    }

    public function testAddsAGalleryImageWhenIsCoverIsFalse(): void
    {
        $anime = $this->persistAnime();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        $downloader = $this->createMock(PluginMediaDownloaderInterface::class);
        $downloader->expects($this->once())
            ->method('download')
            ->with($animeId, 'https://example.test/1.jpg')
            ->willReturn('def456.webp');

        $handler = new DownloadAnimeMediaMessageHandler($this->entityManager, $downloader, new NullLogger());
        $handler(new DownloadAnimeMediaMessage($animeId, 'https://example.test/1.jpg', false));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(MovieAnime::class, $animeId);
        $sources = array_map(static fn ($image): string => $image->source, $reloaded?->getImages()->toArray() ?? []);
        $this->assertSame(['def456.webp'], $sources);
    }

    /**
     * A redelivered message for the same URL (Messenger's at-least-once delivery, or simply the
     * same URL appearing twice in PluginAnimeData::$images) must not create a second gallery
     * entry — same dedup-by-filename rule PluginAnimeDataMerger::applyImages() enforces for the
     * point fill-in scenario (issue #508).
     */
    public function testReprocessingTheSameUrlDoesNotDuplicateTheGalleryImage(): void
    {
        $anime = $this->persistAnime();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        // Both deliveries must actually reach the downloader — the dedup this test is about
        // happens when applying the result, not by skipping the second download.
        $downloader = $this->createMock(PluginMediaDownloaderInterface::class);
        $downloader->expects($this->exactly(2))
            ->method('download')
            ->with($animeId, 'https://example.test/1.jpg')
            ->willReturn('def456.webp');

        $handler = new DownloadAnimeMediaMessageHandler($this->entityManager, $downloader, new NullLogger());
        $handler(new DownloadAnimeMediaMessage($animeId, 'https://example.test/1.jpg', false));
        $handler(new DownloadAnimeMediaMessage($animeId, 'https://example.test/1.jpg', false));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(MovieAnime::class, $animeId);
        $sources = array_map(static fn ($image): string => $image->source, $reloaded?->getImages()->toArray() ?? []);
        $this->assertSame(['def456.webp'], $sources);
    }

    public function testLogsAWarningAndAppliesNothingWhenTheDownloadFails(): void
    {
        $anime = $this->persistAnime();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        $downloader = $this->createStub(PluginMediaDownloaderInterface::class);
        $downloader->method('download')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('Discarding'),
            $this->callback(static fn (array $context): bool => $context['animeId'] === $animeId && $context['url'] === 'https://example.test/cover.jpg'),
        );

        $handler = new DownloadAnimeMediaMessageHandler($this->entityManager, $downloader, $logger);
        $handler(new DownloadAnimeMediaMessage($animeId, 'https://example.test/cover.jpg', true));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(MovieAnime::class, $animeId);
        $this->assertNull($reloaded?->getCover());
    }

    public function testDoesNothingWhenTheAnimeNoLongerExists(): void
    {
        $downloader = $this->createMock(PluginMediaDownloaderInterface::class);
        $downloader->expects($this->never())->method('download');

        $handler = new DownloadAnimeMediaMessageHandler($this->entityManager, $downloader, new NullLogger());
        $handler(new DownloadAnimeMediaMessage(999, 'https://example.test/cover.jpg', true));
    }
}
