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

namespace App\Tests\Unit\Command;

use App\Command\SearchReindexCommand;
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SearchReindexCommandTest extends TestCase
{
    public function testReindexesTheCatalogAndPrintsTheCount(): void
    {
        $entityManager = $this->createEntityManager();

        foreach (['Cowboy Bebop', 'Trigun'] as $title) {
            $anime = new MovieAnime();
            $anime->setTitle($title)->setWatchStatus(WatchStatus::Plan);
            $entityManager->persist($anime);
        }
        $entityManager->flush();

        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('updateSettings')->willReturn(['taskUid' => 1]);
        $index->expects($this->exactly(2))->method('addDocuments')->willReturn(['taskUid' => 2]);
        $index->method('waitForTask');

        $client = $this->createMock(Client::class);
        $client->method('index')->with('anime')->willReturn($index);

        $reindexService = new AnimeReindexService($entityManager, new AnimeSearchIndexer($client));
        $tester = new CommandTester(new SearchReindexCommand($reindexService));

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Reindexed 2 anime.', $tester->getDisplay());
    }

    private function createEntityManager(): EntityManager
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
        $entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}
