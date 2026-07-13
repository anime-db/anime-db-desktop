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

namespace App\Tests\Unit\Service\Search;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Service\Search\AnimeReindexService;
use App\Service\Search\AnimeSearchIndexer;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * Same doubling strategy as IndexAnimeMessageHandlerTest: AnimeSearchIndexer is final and talks
 * to a real Meilisearch\Client, so the Client is what gets doubled here, not the indexer.
 */
final class AnimeReindexServiceTest extends TestCase
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

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    public function testConfiguresTheIndexAndReindexesEveryAnime(): void
    {
        $this->persistAnime(3);

        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('updateSettings')->willReturn(['taskUid' => 1]);
        $index->expects($this->exactly(3))->method('addDocuments')->willReturn(['taskUid' => 2]);
        $index->method('waitForTask');

        $client = $this->createMock(Client::class);
        $client->method('index')->with('anime')->willReturn($index);

        $service = new AnimeReindexService($this->entityManager, new AnimeSearchIndexer($client));

        $this->assertSame(3, $service->reindexAll());
    }

    public function testReturnsZeroWithoutIndexingWhenTheCatalogIsEmpty(): void
    {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('updateSettings')->willReturn(['taskUid' => 1]);
        $index->expects($this->never())->method('addDocuments');
        $index->method('waitForTask');

        $client = $this->createMock(Client::class);
        $client->method('index')->with('anime')->willReturn($index);

        $service = new AnimeReindexService($this->entityManager, new AnimeSearchIndexer($client));

        $this->assertSame(0, $service->reindexAll());
    }

    /**
     * Catalog larger than one page (private AnimeReindexService::PAGE_SIZE) exercises the
     * LIMIT/OFFSET loop across more than one iteration instead of only ever running its body once.
     */
    public function testReindexesAcrossMultiplePages(): void
    {
        $pageSize = new \ReflectionClassConstant(AnimeReindexService::class, 'PAGE_SIZE');
        $total = $pageSize->getValue() + 5;

        $this->persistAnime($total);

        $index = $this->createMock(Indexes::class);
        $index->method('updateSettings')->willReturn(['taskUid' => 1]);
        $index->expects($this->exactly($total))->method('addDocuments')->willReturn(['taskUid' => 2]);
        $index->method('waitForTask');

        $client = $this->createMock(Client::class);
        $client->method('index')->with('anime')->willReturn($index);

        $service = new AnimeReindexService($this->entityManager, new AnimeSearchIndexer($client));

        $this->assertSame($total, $service->reindexAll());
    }

    private function persistAnime(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $anime = new MovieAnime();
            $anime->setTitle('Anime '.$i)->setWatchStatus(WatchStatus::Plan);
            $this->entityManager->persist($anime);
        }

        $this->entityManager->flush();
    }
}
