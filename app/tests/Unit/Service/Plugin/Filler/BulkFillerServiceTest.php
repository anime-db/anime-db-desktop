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
use AnimeDb\PluginContracts\Model\AnimeType as ContractsAnimeType;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate as ContractsSearchByPluginCandidate;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\DownloadAnimeMediaMessage;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Service\Plugin\Exception\ExternalIdAlreadyClaimedException;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\CachedFillerLookup;
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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class BulkFillerServiceTest extends TestCase
{
    private EntityManager $entityManager;
    private AnimeRepository $animeRepository;

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

        $this->animeRepository = new AnimeRepository($this->entityManager);
    }

    /**
     * @param iterable<string, FillerInterface> $fillers
     */
    private function newService(iterable $fillers, ?LoggerInterface $logger = null, ?MessageBusInterface $messageBus = null, ?CachedFillerLookup $lookup = null): BulkFillerService
    {
        return new BulkFillerService(
            new FillerRegistry($fillers, new PluginsConfigStore(sys_get_temp_dir().'/anime-bulk-filler-test-'.uniqid().'.json')),
            new PluginAnimeDataMerger(
                new StudioRepository($this->entityManager),
                $this->entityManager,
                $this->createStub(PluginMediaDownloaderInterface::class),
            ),
            $this->entityManager,
            $logger ?? new NullLogger(),
            $messageBus ?? $this->createMock(MessageBusInterface::class),
            $this->animeRepository,
            $lookup ?? new CachedFillerLookup(new ArrayAdapter()),
        );
    }

    public function testFillNewFromPluginReturnsNullWhenNoFillerIsRegistered(): void
    {
        $service = $this->newService([]);

        $this->assertNull($service->fillNewFromPlugin(new PluginId('animedb-shikimori'), 'Bleach'));
    }

    public function testFillNewFromPluginReturnsNullWhenPluginCannotFindTheTitle(): void
    {
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willReturn([]);

        $service = $this->newService(['animedb-shikimori' => $filler]);

        $this->assertNull($service->fillNewFromPlugin(new PluginId('animedb-shikimori'), 'Bleach'));
    }

    public function testFillNewFromPluginCreatesAnimeOfPluginReportedTypeAndRemembersExternalId(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach: Memories of Nobody', type: ContractsAnimeType::Movie, durationMinutes: 91);

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willReturn([new ContractsSearchByPluginCandidate((string) $pluginId, 'Bleach: Memories of Nobody', '104')]);
        $filler->method('findById')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type', 'durationMinutes']);

        $service = $this->newService([(string) $pluginId => $filler]);

        $anime = $service->fillNewFromPlugin($pluginId, 'Bleach: Memories of Nobody');

        $this->assertInstanceOf(MovieAnime::class, $anime);
        $this->assertSame('Bleach: Memories of Nobody', $anime->getTitle());
        $this->assertSame(91, $anime->getDurationMinutes());
        $this->assertSame('104', $anime->getExternalId($pluginId, $filler));
    }

    /**
     * A caller re-requesting the same external id twice without checking for an existing
     * match first (this test's setup, not a realistic caller) still hits the real
     * anime_external_id UNIQUE(plugin_id, external_id) constraint on the second attempt
     * (issue #297) — build() does not silently produce a second Anime for an id it already
     * created. findById() itself is still only called once, proving the cache is what saves
     * the round trip, not a resolve()-style pre-check.
     */
    public function testFillNewFromPluginCallsFindByIdOnlyOnceForRepeatedExternalId(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach');

        $filler = $this->createMock(FillerInterface::class);
        $filler->method('find')->willReturn([new ContractsSearchByPluginCandidate((string) $pluginId, 'Bleach', '104')]);
        $filler->expects($this->once())->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title']);

        $service = $this->newService([(string) $pluginId => $filler]);

        $first = $service->fillNewFromPlugin($pluginId, 'Bleach');

        $this->expectException(ExternalIdAlreadyClaimedException::class);

        try {
            $service->fillNewFromPlugin($pluginId, 'Bleach');
        } catch (ExternalIdAlreadyClaimedException $e) {
            $this->assertSame($first?->id, $e->animeId);

            throw $e;
        }
    }

    public function testFillNewFromPluginSkipsFindWhenExternalIdIsAlreadyKnown(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach: Memories of Nobody', type: ContractsAnimeType::Movie, durationMinutes: 91);

        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->never())->method('find');
        $filler->expects($this->once())->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type', 'durationMinutes']);

        $service = $this->newService([(string) $pluginId => $filler]);

        $anime = $service->fillNewFromPlugin($pluginId, 'Bleach: Memories of Nobody', '104');

        $this->assertInstanceOf(MovieAnime::class, $anime);
        $this->assertSame('104', $anime->getExternalId($pluginId, $filler));
    }

    public function testFillNewFromPluginReturnsNullAndLogsAWarningWhenFindThrows(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willThrowException(new \RuntimeException('external source unreachable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('bulk-fill'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === (string) $pluginId
                && $context['exception'] instanceof \RuntimeException),
        );

        $service = $this->newService([(string) $pluginId => $filler], $logger);

        $this->assertNull($service->fillNewFromPlugin($pluginId, 'Bleach'));
    }

    public function testFillNewFromPluginReturnsNullAndLogsAWarningWhenFindByIdThrowsForAnAlreadyKnownExternalId(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->willThrowException(new \RuntimeException('external source unreachable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('bulk-fill'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === (string) $pluginId
                && $context['exception'] instanceof \RuntimeException),
        );

        $service = $this->newService([(string) $pluginId => $filler], $logger);

        $this->assertNull($service->fillNewFromPlugin($pluginId, 'Bleach', '104'));
    }

    /**
     * fillNewFrom() (issue #257 review, pull-sync) resolves directly by external id — no
     * find()-by-title round trip — and works off a filler instance the caller already has in
     * hand, bypassing FillerRegistry entirely: the empty registry below proves that.
     */
    public function testFillNewFromResolvesByExternalIdWithoutGoingThroughTheFillerRegistry(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach: Memories of Nobody', type: ContractsAnimeType::Movie, durationMinutes: 91);

        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->never())->method('find');
        $filler->expects($this->once())->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type', 'durationMinutes']);

        $service = $this->newService([]);

        $anime = $service->fillNewFrom($filler, $pluginId, '104');

        $this->assertInstanceOf(MovieAnime::class, $anime);
        $this->assertSame('Bleach: Memories of Nobody', $anime->getTitle());
        $this->assertSame(91, $anime->getDurationMinutes());
        $this->assertSame('104', $anime->getExternalId($pluginId, $filler));
    }

    public function testFillNewFromReturnsNullWhenTheFillerCannotResolveTheExternalId(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->willReturn(null);

        $service = $this->newService([]);

        $this->assertNull($service->fillNewFrom($filler, $pluginId, '104'));
    }

    /**
     * cover/images are no longer excluded from bulk fill-in (issue #508), but they are also
     * never downloaded synchronously by this service — see PluginAnimeDataMerger's own
     * applyCover()/applyImages() (untouched, still used by the point fill-in scenario), which
     * this test proves never ran by asserting getCover() is still null right after build().
     */
    public function testFillNewFromPluginDispatchesADownloadMessageForTheCoverInsteadOfDownloadingItSynchronously(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', cover: 'https://example.test/cover.jpg');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willReturn([new ContractsSearchByPluginCandidate((string) $pluginId, 'Bleach', '104')]);
        $filler->method('findById')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type', 'cover']);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (DownloadAnimeMediaMessage $message): bool => $message->url === 'https://example.test/cover.jpg' && $message->isCover))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $service = $this->newService([(string) $pluginId => $filler], messageBus: $messageBus);

        $anime = $service->fillNewFromPlugin($pluginId, 'Bleach');

        $this->assertInstanceOf(Anime::class, $anime);
        $this->assertNull($anime->getCover());
    }

    /**
     * One message per URL, not one per anime (issue #508): a gallery of several images must not
     * become a single message that would occupy the consumer for as long as the whole batch
     * takes.
     */
    public function testFillNewFromPluginDispatchesOneDownloadMessagePerImageUrl(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', images: ['https://example.test/1.jpg', 'https://example.test/2.jpg']);

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willReturn([new ContractsSearchByPluginCandidate((string) $pluginId, 'Bleach', '104')]);
        $filler->method('findById')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type', 'images']);

        $dispatchedUrls = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (DownloadAnimeMediaMessage $message) use (&$dispatchedUrls): Envelope {
                $dispatchedUrls[] = $message->url;
                $this->assertFalse($message->isCover);

                return new Envelope($message);
            });

        $service = $this->newService([(string) $pluginId => $filler], messageBus: $messageBus);
        $service->fillNewFromPlugin($pluginId, 'Bleach');

        $this->assertSame(['https://example.test/1.jpg', 'https://example.test/2.jpg'], $dispatchedUrls);
    }

    /**
     * A filler that never declared 'cover' as one of its {@see FillerInterface::getFillableFields()}
     * doesn't get a download queued just because $data happens to carry one — same gate
     * PluginAnimeDataMerger::apply() itself enforces for every other field.
     */
    public function testFillNewFromPluginDoesNotDispatchACoverDownloadWhenTheFillerDoesNotDeclareCoverAsFillable(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', cover: 'https://example.test/cover.jpg');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willReturn([new ContractsSearchByPluginCandidate((string) $pluginId, 'Bleach', '104')]);
        $filler->method('findById')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type']);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $service = $this->newService([(string) $pluginId => $filler], messageBus: $messageBus);

        $this->assertInstanceOf(Anime::class, $service->fillNewFromPlugin($pluginId, 'Bleach'));
    }

    public function testFindOrCreateFromPluginReturnsTheExistingAnimeWhenThePairAlreadyResolves(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $existing = new TvAnime();
        $existing->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        $existing->rememberExternalId($pluginId, '104');
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->never())->method('findById');

        $service = $this->newService([(string) $pluginId => $filler]);

        $result = $service->findOrCreateFromPlugin($pluginId, '104', 'Bleach');

        $this->assertTrue($result->wasFound);
        $this->assertSame($existing, $result->anime);
        $this->assertSame(1, \count($this->entityManager->getRepository(Anime::class)->findAll()));
    }

    public function testFindOrCreateFromPluginCreatesAndFillsInANewAnimeWhenNotFound(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach: Memories of Nobody', type: ContractsAnimeType::Movie, durationMinutes: 91);

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type', 'durationMinutes']);

        $service = $this->newService([(string) $pluginId => $filler]);

        $result = $service->findOrCreateFromPlugin($pluginId, '104', 'Bleach: Memories of Nobody');

        $this->assertFalse($result->wasFound);
        $this->assertTrue($result->filledFromPlugin);
        $this->assertInstanceOf(MovieAnime::class, $result->anime);
        $this->assertSame(91, $result->anime->getDurationMinutes());
        $this->assertSame('104', $result->anime->getExternalId($pluginId, $filler));
    }

    public function testFindOrCreateFromPluginFallsBackToATitleOnlyAnimeWithTheExternalIdPreservedWhenThePluginIsUnreachable(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->willThrowException(new \RuntimeException('unreachable'));
        $filler->method('getFillableFields')->willReturn(['title']);

        $service = $this->newService([(string) $pluginId => $filler]);

        $result = $service->findOrCreateFromPlugin($pluginId, '104', 'Bleach');

        $this->assertFalse($result->wasFound);
        $this->assertFalse($result->filledFromPlugin);
        $this->assertSame('Bleach', $result->anime->getTitle());
        $this->assertSame('104', $result->anime->getExternalId($pluginId, $filler));
    }

    public function testFindOrCreateFromPluginWithAnEmptyExternalIdCreatesATitleOnlyAnimeWithoutLinkingAnything(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $service = $this->newService([]);

        $result = $service->findOrCreateFromPlugin($pluginId, '', 'Bleach');

        $this->assertFalse($result->wasFound);
        $this->assertFalse($result->filledFromPlugin);
        $this->assertSame('Bleach', $result->anime->getTitle());
        $this->assertNull($result->anime->getCachedExternalId($pluginId));
    }

    /**
     * Regression guard (issue #832): a genuine concurrent create race — two calls resolving
     * "not found" for the same (pluginId, externalId) before either has linked it — must not
     * leave findOrCreateFromPlugin() throwing into a closed EntityManager; it must resolve the
     * winner instead. Simulated on a *real* EntityManager/SQLite connection (not a mock), via an
     * AnimeRepository double whose resolve() answers "not found" exactly once so the second,
     * concurrent-in-spirit create attempt still hits the real anime_external_id UNIQUE
     * constraint — the same technique this class's own
     * testFillNewFromPluginCallsFindByIdOnlyOnceForRepeatedExternalId() already relies on to
     * reach that constraint for fillNewFromPlugin().
     */
    public function testFindOrCreateFromPluginResolvesTheWinnerWithoutClosingTheEntityManagerWhenARaceIsLost(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title']);

        $racyAnimeRepository = new class($this->entityManager) extends AnimeRepository {
            public int $calls = 0;

            public function resolve(PluginId $pluginId, string $externalId): ?Anime
            {
                ++$this->calls;

                return $this->calls === 1 ? null : parent::resolve($pluginId, $externalId);
            }
        };

        $service = new BulkFillerService(
            new FillerRegistry([(string) $pluginId => $filler], new PluginsConfigStore('')),
            new PluginAnimeDataMerger(
                new StudioRepository($this->entityManager),
                $this->entityManager,
                $this->createStub(PluginMediaDownloaderInterface::class),
            ),
            $this->entityManager,
            new NullLogger(),
            $this->createMock(MessageBusInterface::class),
            $racyAnimeRepository,
            new CachedFillerLookup(new ArrayAdapter()),
        );

        // Seeds the "winner": created directly through the create-only entry point, which does
        // not check resolve() first, so it does not go through $racyAnimeRepository's first
        // (forced-null) answer.
        $winner = $service->fillNewFromPlugin($pluginId, 'Bleach', '104');

        // The real anime_external_id UNIQUE constraint is what actually decided this race — by
        // the time findOrCreateFromPlugin() gets here, $racyAnimeRepository already forced its
        // own up-front resolve() to answer "not found", so this call could only have avoided the
        // conflict by skipping the create path entirely, which it must not: the whole point of
        // this test is that the constraint, not a prior SELECT, is the authority (issue #297/#832).
        $result = $service->findOrCreateFromPlugin($pluginId, '104', 'Bleach');

        // Doctrine closes the EntityManager for good as its own reaction to any failed flush —
        // that is expected here, not a regression (see findOrCreateFromPlugin()'s own docblock).
        // The invariant this test actually guards is narrower: no persist()/flush() happened
        // *after* the conflict (which would throw EntityManagerClosed) — resolving the winner via
        // a plain SELECT still works, and that is exactly what wasFound/anime below prove.
        $this->assertTrue($result->wasFound);
        $this->assertSame($winner?->id, $result->anime->id);
        $this->assertSame(1, \count($this->entityManager->getRepository(Anime::class)->findAll()));
    }

    public function testFillExistingFromPluginFillsOnlyEmptyFieldsAndNeverOverwritesAlreadyFilledOnes(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach', durationMinutes: 91, episodesCount: 366);

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'durationMinutes', 'episodesCount']);

        $anime = new TvAnime();
        $anime->setTitle('Bleach (user title)')->setWatchStatus(WatchStatus::Plan);
        $anime->setDurationMinutes(24); // already filled by the user — must not be overwritten
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $service = $this->newService([(string) $pluginId => $filler]);

        $result = $service->fillExistingFromPlugin($anime, $pluginId, '104');

        $this->assertSame(FillResult::Applied, $result);
        $this->assertSame('Bleach (user title)', $anime->getTitle());
        $this->assertSame(24, $anime->getDurationMinutes());
        $this->assertSame(366, $anime->getEpisodesCount());
        $this->assertSame('104', $anime->getExternalId($pluginId, $filler));
    }

    public function testFillExistingFromPluginThrowsWhenThePairAlreadyBelongsToADifferentAnime(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $owner = new TvAnime();
        $owner->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        $owner->rememberExternalId($pluginId, '104');
        $this->entityManager->persist($owner);

        $other = new TvAnime();
        $other->setTitle('Something Else')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title']);

        $service = $this->newService([(string) $pluginId => $filler]);

        $this->expectException(ExternalIdAlreadyClaimedException::class);
        $service->fillExistingFromPlugin($other, $pluginId, '104');
    }
}
