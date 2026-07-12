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

namespace App\Tests\Unit\Repository;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Studio;
use App\Repository\StudioRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class StudioRepositoryTest extends TestCase
{
    private EntityManager $entityManager;
    private StudioRepository $repository;

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

        $this->repository = new StudioRepository($this->entityManager);
    }

    private function createStudio(string $name): Studio
    {
        $studio = new Studio();
        $studio->rename($name);

        return $studio;
    }

    public function testFindOneByNameReturnsExactMatch(): void
    {
        $this->entityManager->persist($this->createStudio('Madhouse'));
        $this->entityManager->persist($this->createStudio('Bones'));
        $this->entityManager->flush();

        $found = $this->repository->findOneByName('Madhouse');

        $this->assertNotNull($found);
        $this->assertSame('Madhouse', $found->name);
    }

    public function testFindOneByNameReturnsNullWhenNoStudioMatches(): void
    {
        $this->entityManager->persist($this->createStudio('Madhouse'));
        $this->entityManager->flush();

        $this->assertNull($this->repository->findOneByName('Unknown'));
    }

    public function testFindAllOrderedByNameReturnsAlphabeticalOrder(): void
    {
        $this->entityManager->persist($this->createStudio('Sunrise'));
        $this->entityManager->persist($this->createStudio('Bones'));
        $this->entityManager->persist($this->createStudio('Madhouse'));
        $this->entityManager->flush();

        $names = array_map(static fn (Studio $studio): string => $studio->name, $this->repository->findAllOrderedByName());

        $this->assertSame(['Bones', 'Madhouse', 'Sunrise'], $names);
    }
}
