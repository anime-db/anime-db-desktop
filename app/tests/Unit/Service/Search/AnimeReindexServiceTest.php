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

namespace App\Tests\Unit\Service\Search;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Studio;
use App\Service\Search\AnimeReindexService;
use App\Service\Search\AnimeSearchIndexer;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
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
        [$this->entityManager] = $this->createEntityManager();
    }

    /**
     * @return array{EntityManager, QueryCountingLogger}
     */
    private function createEntityManager(): array
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $queryLogger = new QueryCountingLogger();
        $config->setMiddlewares([new Middleware($queryLogger)]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return [$entityManager, $queryLogger];
    }

    public function testConfiguresTheIndexAndReindexesEveryAnime(): void
    {
        $this->persistAnime(3);

        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('updateSettings')->willReturn(['taskUid' => 1]);
        $index->expects($this->once())->method('deleteAllDocuments')->willReturn(['taskUid' => 4]);
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
        $index->expects($this->once())->method('deleteAllDocuments')->willReturn(['taskUid' => 4]);
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
        $index->expects($this->once())->method('deleteAllDocuments')->willReturn(['taskUid' => 4]);
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

    /**
     * Regression test for issue #207: each of the five *-to-many collections
     * (AnimeSearchIndexer::toDocument() reads names/genres/themes/studios/labels) is
     * populated so lazy-loading them per row would produce a query count that scales with
     * the number of anime on the page. The fetch-joined page query must keep the number of
     * executed SQL statements constant instead, regardless of how many anime it contains.
     */
    public function testDoesNotIssueANQueryPerAnimeWhenLoadingAPage(): void
    {
        $queriesForTwoAnime = $this->countQueriesToReindex(2);
        $queriesForFiveAnime = $this->countQueriesToReindex(5);

        $this->assertSame(
            $queriesForTwoAnime,
            $queriesForFiveAnime,
            'query count for one page must not grow with the number of anime on it',
        );
    }

    private function countQueriesToReindex(int $animeCount): int
    {
        [$entityManager, $queryLogger] = $this->createEntityManager();
        $this->populateCollections($entityManager, $animeCount);

        $index = $this->createMock(Indexes::class);
        $index->method('updateSettings')->willReturn(['taskUid' => 1]);
        $index->method('deleteAllDocuments')->willReturn(['taskUid' => 4]);
        $index->expects($this->exactly($animeCount))->method('addDocuments')->willReturn(['taskUid' => 2]);
        $index->method('waitForTask');

        $client = $this->createMock(Client::class);
        $client->method('index')->with('anime')->willReturn($index);

        $service = new AnimeReindexService($entityManager, new AnimeSearchIndexer($client));

        $queryLogger->reset();
        $service->reindexAll();

        return $queryLogger->getCount();
    }

    /**
     * Persists $animeCount anime, each with two rows in every one of the five collections
     * AnimeSearchIndexer::toDocument() reads, then clears the identity map so the subsequent
     * reindex is forced to load them from the database rather than reuse in-memory objects.
     */
    private function populateCollections(EntityManager $entityManager, int $animeCount): void
    {
        $studios = [new Studio(), new Studio()];
        $labels = [new Label('label-a'), new Label('label-b')];

        foreach ($studios as $i => $studio) {
            $studio->rename('Studio '.$i);
            $entityManager->persist($studio);
        }
        foreach ($labels as $label) {
            $entityManager->persist($label);
        }

        for ($i = 0; $i < $animeCount; ++$i) {
            $anime = new MovieAnime();
            $anime->setTitle('Anime '.$i)->setWatchStatus(WatchStatus::Plan);
            $anime->addName('Name A', AnimeNameType::English)->addName('Name B', AnimeNameType::Russian);
            $anime->addGenre(GenreCode::Action)->addGenre(GenreCode::Adventure);
            $anime->addTheme(ThemeCode::AdultCast)->addTheme(ThemeCode::Anthropomorphic);
            foreach ($studios as $studio) {
                $anime->addStudio($studio);
            }
            foreach ($labels as $label) {
                $anime->addLabel($label);
            }
            $entityManager->persist($anime);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
