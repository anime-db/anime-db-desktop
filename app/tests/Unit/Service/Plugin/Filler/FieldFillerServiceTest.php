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

namespace App\Tests\Unit\Service\Plugin\Filler;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate as ContractsSearchByPluginCandidate;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\StudioRepository;
use App\Service\Plugin\Filler\CachedFillerLookup;
use App\Service\Plugin\Filler\FieldFillerService;
use App\Service\Plugin\Filler\FillResult;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

final class FieldFillerServiceTest extends TestCase
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

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 5).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    private function persistedAnime(string $title = 'Bleach'): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    /** @param iterable<string, FillerInterface> $fillers */
    private function newService(
        iterable $fillers,
        ?CacheInterface $cache = null,
        ?LoggerInterface $logger = null,
        ?PluginMediaDownloaderInterface $mediaDownloader = null,
    ): FieldFillerService {
        return new FieldFillerService(
            new FillerRegistry($fillers, new PluginsConfigStore(sys_get_temp_dir().'/anime-field-filler-test-'.uniqid().'.json')),
            new PluginAnimeDataMerger(
                new StudioRepository($this->entityManager),
                $this->entityManager,
                $mediaDownloader ?? $this->createStub(PluginMediaDownloaderInterface::class),
                new NullLogger(),
            ),
            $this->entityManager,
            new CachedFillerLookup($cache ?? new ArrayAdapter()),
            $logger ?? new NullLogger(),
        );
    }

    public function testFillReturnsFalseWhenNoFillerIsRegisteredForThePluginId(): void
    {
        $service = $this->newService([]);

        $this->assertSame(FillResult::NotFound, $service->fill($this->persistedAnime(), new PluginId('animedb-shikimori'), 'genres'));
    }

    public function testFillReturnsFalseWhenThePluginDoesNotClaimToSupportTheField(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $filler = $this->createMock(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title']);
        $filler->expects($this->never())->method('find');
        $filler->expects($this->never())->method('findById');

        $service = $this->newService([(string) $pluginId => $filler]);

        $this->assertSame(FillResult::NotFound, $service->fill($this->persistedAnime(), $pluginId, 'genres'));
    }

    public function testFillSkipsFindWhenThePluginResolvesAnExternalIdFromTheAnimeSSourceUrl(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', durationMinutes: 24);

        $filler = $this->createMock(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['durationMinutes']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->expects($this->never())->method('find');
        $filler->expects($this->once())->method('findById')->with('104')->willReturn($data);

        $anime = $this->persistedAnime();

        $service = $this->newService([(string) $pluginId => $filler]);

        $this->assertSame(FillResult::Applied, $service->fill($anime, $pluginId, 'durationMinutes'));
        $this->assertSame(24, $anime->getDurationMinutes());
        $this->assertSame('104', $anime->getCachedExternalId($pluginId));
    }

    public function testFillFallsBackToFindByNameAndRemembersTheResolvedExternalId(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', durationMinutes: 24);

        $filler = $this->createMock(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['durationMinutes']);
        $filler->method('resolveExternalId')->willReturn(null);
        $filler->method('find')->with('Bleach')->willReturn([new ContractsSearchByPluginCandidate((string) $pluginId, 'Bleach', '104')]);
        $filler->expects($this->once())->method('findById')->with('104')->willReturn($data);

        $anime = $this->persistedAnime();

        $service = $this->newService([(string) $pluginId => $filler]);

        $this->assertSame(FillResult::Applied, $service->fill($anime, $pluginId, 'durationMinutes'));
        $this->assertSame(24, $anime->getDurationMinutes());
        $this->assertSame('104', $anime->getCachedExternalId($pluginId));
    }

    public function testFillReturnsFalseWhenFindReturnsNoCandidates(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $filler = $this->createMock(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['durationMinutes']);
        $filler->method('resolveExternalId')->willReturn(null);
        $filler->method('find')->willReturn([]);
        $filler->expects($this->never())->method('findById');

        $anime = $this->persistedAnime();

        $service = $this->newService([(string) $pluginId => $filler]);

        $this->assertSame(FillResult::NotFound, $service->fill($anime, $pluginId, 'durationMinutes'));
        $this->assertNull($anime->getDurationMinutes());
    }

    public function testFillReturnsFalseWhenFindByIdCannotResolveTheExternalId(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['durationMinutes']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn(null);

        $anime = $this->persistedAnime();

        $service = $this->newService([(string) $pluginId => $filler]);

        $this->assertSame(FillResult::NotFound, $service->fill($anime, $pluginId, 'durationMinutes'));
        $this->assertNull($anime->getDurationMinutes());
    }

    public function testFillReturnsFalseAndLogsAWarningWhenAPluginCallThrows(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['durationMinutes']);
        $filler->method('resolveExternalId')->willThrowException(new \RuntimeException('external source unreachable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('filling a single field'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === (string) $pluginId
                && $context['field'] === 'durationMinutes'
                && $context['exception'] instanceof \RuntimeException),
        );

        $anime = $this->persistedAnime();

        $service = $this->newService([(string) $pluginId => $filler], null, $logger);

        $this->assertSame(FillResult::NotFound, $service->fill($anime, $pluginId, 'durationMinutes'));
    }

    public function testFillCallsFindByIdOnlyOnceForTheSamePluginAndExternalIdAcrossCalls(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', durationMinutes: 24, episodesCount: 366);

        $filler = $this->createMock(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['durationMinutes', 'episodesCount']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->expects($this->once())->method('findById')->with('104')->willReturn($data);

        $anime = $this->persistedAnime();
        $cache = new ArrayAdapter();

        $service = $this->newService([(string) $pluginId => $filler], $cache);

        $this->assertSame(FillResult::Applied, $service->fill($anime, $pluginId, 'durationMinutes'));
        $this->assertSame(FillResult::Applied, $service->fill($anime, $pluginId, 'episodesCount'));
        $this->assertSame(24, $anime->getDurationMinutes());
    }

    public function testFillDoesNotCacheANullFindByIdResultSoALaterCallRetriesThePlugin(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', durationMinutes: 24);

        $filler = $this->createMock(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['durationMinutes']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->expects($this->exactly(2))->method('findById')->with('104')->willReturnOnConsecutiveCalls(null, $data);

        $anime = $this->persistedAnime();
        $cache = new ArrayAdapter();

        $service = $this->newService([(string) $pluginId => $filler], $cache);

        $this->assertSame(FillResult::NotFound, $service->fill($anime, $pluginId, 'durationMinutes'));
        $this->assertSame(FillResult::Applied, $service->fill($anime, $pluginId, 'durationMinutes'));
        $this->assertSame(24, $anime->getDurationMinutes());
    }

    public function testFillOnlyAppliesTheRequestedFieldFromTheResolvedData(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', durationMinutes: 24, episodesCount: 366);

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['durationMinutes', 'episodesCount']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn($data);

        $anime = $this->persistedAnime();

        $service = $this->newService([(string) $pluginId => $filler]);

        $this->assertSame(FillResult::Applied, $service->fill($anime, $pluginId, 'durationMinutes'));
        $this->assertSame(24, $anime->getDurationMinutes());
        $this->assertNull($anime->getEpisodesCount());
    }

    /**
     * Issue #860, scenario 8: a point fill-in of 'dateEnd' that conflicts with the anime's
     * already-stored datePremiere must not throw and must not come back as
     * FillResult::ImageRejected — that result is reserved for an actual image/cover download
     * failure (see PluginAnimeDataMerger::apply()'s docblock), not a rejected date pair.
     */
    public function testFillDoesNotReportImageRejectedWhenAPointFillInOfDateEndConflictsWithTheStoredDatePremiere(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', dateEnd: new \DateTimeImmutable('2020-01-01'));

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['dateEnd']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn($data);

        $anime = $this->persistedAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('2020-06-01'));

        $service = $this->newService([(string) $pluginId => $filler]);

        $result = $service->fill($anime, $pluginId, 'dateEnd');

        $this->assertNotSame(FillResult::ImageRejected, $result);
        $this->assertNull($anime->getDateEnd());
    }

    public function testFillReturnsImageRejectedWhenThePluginReturnsACoverUrlThatFailsToDownload(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', cover: 'https://example.test/cover.jpg');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['cover']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn($data);

        $downloader = $this->createStub(PluginMediaDownloaderInterface::class);
        $downloader->method('download')->willReturn(null);

        $anime = $this->persistedAnime();

        $service = $this->newService([(string) $pluginId => $filler], mediaDownloader: $downloader);

        $this->assertSame(FillResult::ImageRejected, $service->fill($anime, $pluginId, 'cover'));
        $this->assertNull($anime->getCover());
    }

    public function testFillReturnsAppliedWhenTheCoverDownloadsSuccessfully(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', cover: 'https://example.test/cover.jpg');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['cover']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn($data);

        $downloader = $this->createStub(PluginMediaDownloaderInterface::class);
        $downloader->method('download')->willReturn('abc123.jpg');

        $anime = $this->persistedAnime();

        $service = $this->newService([(string) $pluginId => $filler], mediaDownloader: $downloader);

        $this->assertSame(FillResult::Applied, $service->fill($anime, $pluginId, 'cover'));
        $this->assertSame('abc123.jpg', $anime->getCover());
    }

    /**
     * Partial success (issue #507): one of two URLs downloads, the other one does not - still
     * Applied, since at least one frame made it into the gallery.
     */
    public function testFillReturnsAppliedForImagesWhenAtLeastOneUrlDownloadsSuccessfully(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', images: ['https://example.test/1.jpg', 'https://example.test/2.jpg']);

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['images']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn($data);

        $downloader = $this->createStub(PluginMediaDownloaderInterface::class);
        $downloader->method('download')->willReturnCallback(
            static fn (int $animeId, string $url): ?string => $url === 'https://example.test/2.jpg' ? 'new.jpg' : null,
        );

        $anime = $this->persistedAnime();

        $service = $this->newService([(string) $pluginId => $filler], mediaDownloader: $downloader);

        $this->assertSame(FillResult::Applied, $service->fill($anime, $pluginId, 'images'));
        $sources = array_map(static fn ($image): string => $image->source, $anime->getImages()->toArray());
        $this->assertSame(['new.jpg'], $sources);
    }

    public function testFillReturnsImageRejectedForImagesWhenEveryUrlFailsToDownload(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', images: ['https://example.test/1.jpg']);

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['images']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturn($data);

        $downloader = $this->createStub(PluginMediaDownloaderInterface::class);
        $downloader->method('download')->willReturn(null);

        $anime = $this->persistedAnime();

        $service = $this->newService([(string) $pluginId => $filler], mediaDownloader: $downloader);

        $this->assertSame(FillResult::ImageRejected, $service->fill($anime, $pluginId, 'images'));
        $this->assertCount(0, $anime->getImages());
    }
}
