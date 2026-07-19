<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use AnimeDb\PluginContracts\AnimeType as ContractsAnimeType;
use AnimeDb\PluginContracts\FillerInterface;
use AnimeDb\PluginContracts\PluginAnimeData;
use AnimeDb\PluginContracts\SyncInterface;
use AnimeDb\PluginContracts\SyncItem;
use AnimeDb\PluginContracts\SyncStatus;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PullSyncService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Verifies the pull() core logic (issue #257): an already-known SyncItem updates its local
 * Anime's watchStatus in place, a genuinely new one is created and filled in through the sync
 * plugin's own filler capability (issue #257 review — no bare title-only stub), and running
 * the same pull() twice in a row never produces a duplicate row.
 */
final class PullSyncServiceTest extends TestCase
{
    private EntityManager $entityManager;
    private PullSyncService $service;
    private PluginId $pluginId;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->service = $this->newService($this->entityManager, new AnimeRepository($this->entityManager));
        $this->pluginId = new PluginId('animedb-shikimori');
    }

    private function newService(EntityManager $entityManager, AnimeRepository $animeRepository): PullSyncService
    {
        $bulkFillerService = new BulkFillerService(
            // Never consulted by fillNewFrom() — it works off the sync plugin instance directly.
            new FillerRegistry([], new PluginsConfigStore(sys_get_temp_dir().'/anime-pull-sync-test-'.uniqid().'.json')),
            new PluginAnimeDataMerger(
                new StudioRepository($entityManager),
                $entityManager,
                $this->createStub(PluginMediaDownloaderInterface::class),
            ),
            $entityManager,
            new NullLogger(),
        );

        return new PullSyncService($entityManager, $animeRepository, $bulkFillerService);
    }

    /**
     * SyncInterface and FillerInterface both extend PluginInterface, so
     * createMockForIntersectionOfInterfaces() rejects them (it treats resolveExternalId(),
     * inherited by both, as a conflicting redeclaration) — a small concrete stub in place of a
     * generated mock instead. $pull and $data are set directly on the returned instance rather
     * than through a constructor, since PHPUnit's own mocks configure expectations the same way.
     *
     * @param iterable<SyncItem> $pull
     * @param string[]           $fillableFields
     */
    private function syncFillerStub(iterable $pull, ?PluginAnimeData $data, array $fillableFields = []): SyncInterface&FillerInterface
    {
        return new class($pull, $data, $fillableFields) implements SyncInterface, FillerInterface {
            /**
             * @param iterable<SyncItem> $pull
             * @param string[]           $fillableFields
             */
            public function __construct(
                private readonly iterable $pull,
                private readonly ?PluginAnimeData $data,
                private readonly array $fillableFields,
            ) {
            }

            public function resolveExternalId(array $urls): ?string
            {
                return null;
            }

            public function push(SyncItem $item): void
            {
            }

            public function pull(): iterable
            {
                return $this->pull;
            }

            public function find(string $name, ?callable $onHeartbeat = null): array
            {
                return [];
            }

            public function findById(string $externalId): ?PluginAnimeData
            {
                return $this->data;
            }

            public function getFillableFields(): array
            {
                return $this->fillableFields;
            }
        };
    }

    public function testUpdatesTheWatchStatusOfAnAlreadySyncedAnime(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId($this->pluginId, '1');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop')]);

        $this->service->pull($this->pluginId, $sync);

        $this->assertCount(1, $this->allAnime());
        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    public function testSkipsAnUnknownExternalIdWhenTheFillerCannotResolveIt(): void
    {
        $sync = $this->syncFillerStub([new SyncItem('42', SyncStatus::Watching, 'Trigun')], data: null);

        $this->service->pull($this->pluginId, $sync);

        $this->assertCount(0, $this->allAnime());
    }

    public function testCreatesAndFillsInANewAnimeThroughTheSyncPluginsOwnFillerCapability(): void
    {
        $data = new PluginAnimeData(
            title: 'Trigun',
            type: ContractsAnimeType::Tv,
            datePremiere: new \DateTimeImmutable('2 years ago'),
            dateEnd: new \DateTimeImmutable('1 year ago'),
        );

        $sync = $this->syncFillerStub(
            [new SyncItem('42', SyncStatus::Completed, 'Trigun')],
            data: $data,
            fillableFields: ['title', 'type', 'datePremiere', 'dateEnd'],
        );

        $this->service->pull($this->pluginId, $sync);

        $created = $this->allAnime();
        $this->assertCount(1, $created);
        $this->assertSame('Trigun', $created[0]->getTitle());
        $this->assertSame(WatchStatus::Completed, $created[0]->getWatchStatus());
        $this->assertSame('42', $created[0]->getMetadata()['external_id'][(string) $this->pluginId] ?? null);
    }

    public function testRepeatedPullOfTheSameListNeverDuplicatesARow(): void
    {
        $data = new PluginAnimeData(title: 'Trigun', type: ContractsAnimeType::Tv);

        $firstRun = $this->syncFillerStub(
            [new SyncItem('42', SyncStatus::Plan, 'Trigun')],
            data: $data,
            fillableFields: ['title', 'type'],
        );
        $this->service->pull($this->pluginId, $firstRun);
        $this->entityManager->clear();

        $secondRun = $this->createMock(SyncInterface::class);
        $secondRun->expects($this->once())->method('pull')->willReturn([new SyncItem('42', SyncStatus::Watching, 'Trigun')]);
        $this->service->pull($this->pluginId, $secondRun);

        $all = $this->allAnime();
        $this->assertCount(1, $all);
        $this->assertSame(WatchStatus::Watching, $all[0]->getWatchStatus());
    }

    /**
     * Unlike a newly enriched title above, an Anime that's genuinely airing right now
     * (datePremiere in the past, no dateEnd yet) has a reliable Ongoing production status —
     * Anime::setWatchStatus() rejects Completed for that case, and pull() must skip just this
     * item's status update rather than aborting the run.
     */
    public function testSkipsTheStatusUpdateWhenTheLocalAnimeIsActuallyOngoing(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Trigun')->setWatchStatus(WatchStatus::Watching);
        $anime->setDatePremiere(new \DateTimeImmutable('-1 day'));
        $anime->rememberExternalId($this->pluginId, '42');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('42', SyncStatus::Completed, 'Trigun')]);

        $this->service->pull($this->pluginId, $sync);

        $this->assertSame(WatchStatus::Watching, $anime->getWatchStatus());
    }

    /**
     * Regression guard for the pull()-wide O(N×M) reload the review flagged (issue #257):
     * every item in the source list must resolve against the single up-front
     * indexByExternalId() catalog scan, never against a per-item findByExternalId() call.
     * None of these three items has a local match, and the sync mock's inherited findById()
     * default-returns null, so all three are skipped — the point of this test is the single
     * index call, not creation.
     */
    public function testResolvesAWholeListThroughASingleUpFrontIndexRatherThanPerItemLookups(): void
    {
        $repository = $this->createMock(AnimeRepository::class);
        $repository->expects($this->once())
            ->method('indexByExternalId')
            ->with($this->pluginId)
            ->willReturn([]);
        $repository->expects($this->never())->method('findByExternalId');

        $service = $this->newService($this->entityManager, $repository);

        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([
            new SyncItem('1', SyncStatus::Watching, 'Cowboy Bebop'),
            new SyncItem('2', SyncStatus::Plan, 'Trigun'),
            new SyncItem('3', SyncStatus::Plan, 'Bleach'),
        ]);

        $service->pull($this->pluginId, $sync);

        $this->assertCount(0, $this->allAnime());
    }

    /** @return list<Anime> */
    private function allAnime(): array
    {
        /* @var list<Anime> */
        return $this->entityManager->getRepository(Anime::class)->createQueryBuilder('a')
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
