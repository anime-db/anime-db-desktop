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
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class SyncReviewItemRepositoryTest extends TestCase
{
    private EntityManager $entityManager;
    private SyncReviewItemRepository $repository;

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
