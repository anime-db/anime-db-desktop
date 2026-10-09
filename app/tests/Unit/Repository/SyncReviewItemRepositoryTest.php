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
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\SyncReviewItem;
use App\Repository\SyncReviewItemRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as QueryLoggingMiddleware;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class SyncReviewItemRepositoryTest extends TestCase
{
    private EntityManager $entityManager;
    private SyncReviewItemRepository $repository;
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

        $this->repository = new SyncReviewItemRepository($this->entityManager);
    }

    public function testSavePersistsItem(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);

        $this->repository->save($item);

        $this->assertNotNull($item->id);
        $stored = $this->entityManager->getRepository(SyncReviewItem::class)->find($item->id);
        $this->assertNotNull($stored);
        $this->assertSame(SyncReviewItemKind::PotentialDuplicate, $stored->kind);
        $this->assertSame(['anime_ids' => [1, 2]], $stored->payload);
        $this->assertNull($stored->resolvedAt);
    }

    public function testFindAllUnresolvedOrderedByCreatedAtReturnsOnlyUnresolvedItems(): void
    {
        $unresolved = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);
        $resolved = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [3, 4]]);
        $resolved->resolve();

        $this->repository->save($unresolved);
        $this->repository->save($resolved);

        $result = $this->repository->findAllUnresolvedOrderedByCreatedAt();

        $this->assertCount(1, $result);
        $this->assertSame($unresolved->id, $result[0]->id);
    }

    public function testFindAllUnresolvedOrderedByCreatedAtReturnsEmptyArrayWhenNoneExist(): void
    {
        $this->assertSame([], $this->repository->findAllUnresolvedOrderedByCreatedAt());
    }

    /**
     * Acceptance (issue #822): the settings sidebar badge is computed on every settings page
     * load, so {@see SyncReviewItemRepository::countUnresolved()} must count in SQL rather
     * than loading every unresolved item and counting in PHP. Asserting on a QueryBuilder built
     * separately in the test proves nothing about the method under test — instead, a DBAL logging
     * middleware records the SQL the repository *actually* executes, so a rewrite to
     * `count($repository->findBy(...))` (loading every row in PHP) is caught: it would issue a
     * `SELECT` of every column instead of the single `COUNT(...)` query asserted below.
     */
    public function testCountUnresolvedCountsEveryKindInSqlAndSkipsResolved(): void
    {
        foreach (SyncReviewItemKind::cases() as $kind) {
            $this->repository->save(new SyncReviewItem($kind, ['anime_id' => 1]));
        }
        $resolved = new SyncReviewItem(SyncReviewItemKind::NeedsCorrection, ['anime_id' => 2]);
        $resolved->resolve();
        $this->repository->save($resolved);

        $this->queryLogger->executedSql = [];
        $result = $this->repository->countUnresolved();

        $this->assertSame(\count(SyncReviewItemKind::cases()), $result);
        $this->assertCount(1, $this->queryLogger->executedSql, 'countUnresolved() must run exactly one query.');
        $this->assertStringContainsStringIgnoringCase('COUNT(', $this->queryLogger->executedSql[0]);
        $this->assertStringNotContainsStringIgnoringCase('payload', $this->queryLogger->executedSql[0], 'The query must not fetch row data — it must count in SQL, not load rows to count in PHP.');
    }

    public function testCountUnresolvedReturnsZeroWhenNothingIsUnresolved(): void
    {
        $this->assertSame(0, $this->repository->countUnresolved());
    }

    public function testSaveOfResolvedItemPersistsResolvedAt(): void
    {
        $item = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);
        $this->repository->save($item);

        $item->resolve();
        $this->repository->save($item);

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(SyncReviewItem::class)->find($item->id);
        $this->assertNotNull($stored);
        $this->assertNotNull($stored->resolvedAt);
    }
}

/**
 * Collects the SQL of every statement the DBAL connection actually executes, via
 * {@see QueryLoggingMiddleware}, so a test can assert on the query a repository method really ran
 * instead of on a query built separately in the test itself.
 */
final class RecordingSqlLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $executedSql = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (\array_key_exists('sql', $context) && \is_string($context['sql'])) {
            $this->executedSql[] = $context['sql'];
        }
    }
}
