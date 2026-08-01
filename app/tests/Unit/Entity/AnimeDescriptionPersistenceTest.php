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

namespace App\Tests\Unit\Entity;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the UNIQUE(anime_id, locale) violation reported on PR #301: replacing
 * an already-persisted AnimeDescription row via remove+add fails because Doctrine's
 * UnitOfWork issues all INSERTs before any DELETE on flush, so the new row for the same
 * locale collides with the old one that is still in the table. setDescription() must mutate
 * the existing row in place instead. Uses a real EntityManager/SQLite connection since the
 * bug only manifests on an actual flush, not on in-memory collection state.
 */
final class AnimeDescriptionPersistenceTest extends TestCase
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

    public function testReplacingDescriptionForExistingLocaleOnPersistedAnimeSurvivesFlush(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Round trip');
        $anime->setWatchStatus(WatchStatus::Plan);
        $anime->setDescription('ru', 'Original text');

        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = $anime->id;

        $anime->setDescription('ru', 'Updated text');
        $this->entityManager->flush();

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Anime::class, $id);
        $this->assertInstanceOf(Anime::class, $reloaded);
        $this->assertSame('Updated text', $reloaded->getSummary('ru'));
        $this->assertCount(1, $reloaded->getDescriptions());
    }
}
