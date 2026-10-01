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

namespace App\Tests\Unit\Repository;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\TvAnime;
use App\Repository\LabelRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as QueryLoggingMiddleware;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class LabelRepositoryTest extends TestCase
{
    private EntityManager $entityManager;
    private LabelRepository $repository;
    private RecordingSqlLogger $queryLogger;

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

        $this->queryLogger = new RecordingSqlLogger();
        $config->setMiddlewares([new QueryLoggingMiddleware($this->queryLogger)]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->repository = new LabelRepository($this->entityManager);
    }

    public function testFindOneByNameReturnsExactMatch(): void
    {
        $favorite = new Label('favorite');
        $rewatch = new Label('rewatch');
        $this->entityManager->persist($favorite);
        $this->entityManager->persist($rewatch);
        $this->entityManager->flush();

        $found = $this->repository->findOneByName('favorite');

        $this->assertNotNull($found);
        $this->assertSame('favorite', $found->name);
    }

    public function testFindOneByNameReturnsNullWhenNoLabelMatches(): void
    {
        $this->entityManager->persist(new Label('favorite'));
        $this->entityManager->flush();

        $this->assertNull($this->repository->findOneByName('unknown'));
    }

    /**
     * Settings/label/index.html.twig (issue #823) shows how many anime carry each label, and
     * must not issue one COUNT query per label to do it — a DBAL logging middleware records the
     * SQL actually executed, so a regression to a per-label query is caught, same approach as
     * {@see SyncReviewItemRepositoryTest::testCountUnresolvedByKindCountsInSql()}.
     * A label with no linked anime at all must still be present in the result, with a count of 0.
     */
    public function testCountAnimeByLabelCountsAllLabelsInOneQuery(): void
    {
        $popular = new Label('favorite');
        $rare = new Label('rewatch');
        $unused = new Label('plan-to-watch');

        $anime1 = new TvAnime();
        $anime1->setTitle('Anime 1')->setWatchStatus(WatchStatus::Watching);
        $anime1->addLabel($popular);
        $anime2 = new TvAnime();
        $anime2->setTitle('Anime 2')->setWatchStatus(WatchStatus::Watching);
        $anime2->addLabel($popular);
        $anime3 = new TvAnime();
        $anime3->setTitle('Anime 3')->setWatchStatus(WatchStatus::Watching);
        $anime3->addLabel($rare);

        $this->entityManager->persist($popular);
        $this->entityManager->persist($rare);
        $this->entityManager->persist($unused);
        $this->entityManager->persist($anime1);
        $this->entityManager->persist($anime2);
        $this->entityManager->persist($anime3);
        $this->entityManager->flush();

        $this->queryLogger->executedSql = [];
        $counts = $this->repository->countAnimeByLabel();

        $popularId = $popular->id;
        $rareId = $rare->id;
        $unusedId = $unused->id;
        if ($popularId === null || $rareId === null || $unusedId === null) {
            $this->fail('Expected every persisted label to have an id.');
        }

        $this->assertCount(1, $this->queryLogger->executedSql, 'countAnimeByLabel() must run exactly one query.');
        $this->assertSame([
            $popularId => 2,
            $rareId => 1,
            $unusedId => 0,
        ], $counts);
    }
}
