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
use App\Service\Plugin\PullSyncService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the pull() core logic (issue #257): an already-known SyncItem updates its local
 * Anime's watchStatus in place, a genuinely new one is created as a title-only TvAnime, and
 * running the same pull() twice in a row never produces a duplicate row.
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

        $this->service = new PullSyncService($this->entityManager, new AnimeRepository($this->entityManager));
        $this->pluginId = new PluginId('animedb-shikimori');
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

    public function testCreatesANewTitleOnlyAnimeForAnUnknownExternalId(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('42', SyncStatus::Watching, 'Trigun')]);

        $this->service->pull($this->pluginId, $sync);

        $created = $this->allAnime();
        $this->assertCount(1, $created);
        $this->assertSame('Trigun', $created[0]->getTitle());
        $this->assertSame(WatchStatus::Watching, $created[0]->getWatchStatus());
        $this->assertSame('42', $created[0]->getMetadata()['external_id'][(string) $this->pluginId] ?? null);
    }

    public function testRepeatedPullOfTheSameListNeverDuplicatesARow(): void
    {
        $firstRun = $this->createMock(SyncInterface::class);
        $firstRun->expects($this->once())->method('pull')->willReturn([new SyncItem('42', SyncStatus::Plan, 'Trigun')]);
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
     * A source can report "completed" for a title whose local production status isn't
     * Released yet (no filler has run for this brand-new placeholder, so it defaults to
     * ProductionStatus::Announced) — Anime::setWatchStatus() rejects that combination (same
     * invariant AnimeEditableController::updateWatchStatus() enforces for a user edit). pull()
     * must not let that single item's rejection abort the run: the title is still created and
     * linked to its externalId, just left at the Plan status it was created with.
     */
    public function testSkipsTheStatusUpdateWhenTheLocalProductionStatusIsNotReleasedYet(): void
    {
        $sync = $this->createMock(SyncInterface::class);
        $sync->expects($this->once())->method('pull')->willReturn([new SyncItem('42', SyncStatus::Completed, 'Trigun')]);

        $this->service->pull($this->pluginId, $sync);

        $created = $this->allAnime();
        $this->assertCount(1, $created);
        $this->assertSame(WatchStatus::Plan, $created[0]->getWatchStatus());
        $this->assertSame('42', $created[0]->getMetadata()['external_id'][(string) $this->pluginId] ?? null);
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
