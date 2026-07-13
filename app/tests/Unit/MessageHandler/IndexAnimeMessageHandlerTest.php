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

namespace App\Tests\Unit\MessageHandler;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Message\IndexAnimeMessage;
use App\MessageHandler\IndexAnimeMessageHandler;
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
 * AnimeSearchIndexer (issue #196) is final and talks to a real Meilisearch\Client, so instead
 * of doubling the indexer itself, these tests double the Client it wraps — enough to verify
 * this handler loads the current entity by id and forwards it to index(), without a live
 * Meilisearch process (that end-to-end behavior is AnimeSearchIndexerTest's job).
 */
final class IndexAnimeMessageHandlerTest extends TestCase
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

    public function testIndexesTheCurrentStateOfAnExistingAnime(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id after persisting.');

        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(static fn (array $documents): bool => $documents[0]['id'] === $animeId), 'id')
            ->willReturn(['taskUid' => 1]);
        $index->expects($this->once())->method('waitForTask')->with(1);

        $client = $this->createMock(Client::class);
        $client->method('index')->with('anime')->willReturn($index);

        $handler = new IndexAnimeMessageHandler($this->entityManager, new AnimeSearchIndexer($client));
        $handler(new IndexAnimeMessage($animeId));
    }

    public function testDoesNothingWhenTheAnimeNoLongerExists(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('index');

        $handler = new IndexAnimeMessageHandler($this->entityManager, new AnimeSearchIndexer($client));
        $handler(new IndexAnimeMessage(999));
    }
}
