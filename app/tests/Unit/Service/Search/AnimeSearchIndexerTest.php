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
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Studio;
use App\Service\Search\AnimeSearchIndexer;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Meilisearch\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises AnimeSearchIndexer against a real Meilisearch process (v1.13.0, matching
 * scripts/versions.json), the same style of live verification used for
 * docs/spikes/49-meilisearch-ru-morphology.md. Requires a Linux Meilisearch binary
 * outside of bin/ (which only ever holds the Windows .exe download, see
 * scripts/download-bins.js) — point MEILISEARCH_TEST_BINARY at one to run this suite;
 * it is skipped otherwise.
 */
final class AnimeSearchIndexerTest extends TestCase
{
    private const MASTER_KEY = 'test-master-key';

    private ?Process $meilisearch = null;
    private string $dataDir;
    private Client $client;
    private AnimeSearchIndexer $indexer;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $binary = getenv('MEILISEARCH_TEST_BINARY');
        if (!\is_string($binary) || '' === $binary || !is_file($binary)) {
            self::markTestSkipped('Set MEILISEARCH_TEST_BINARY to a Meilisearch binary path to run this integration test.');
        }

        $port = $this->findFreePort();
        $this->dataDir = sys_get_temp_dir().'/anime-db-meilisearch-test-'.bin2hex(random_bytes(8));
        mkdir($this->dataDir, recursive: true);

        $this->meilisearch = new Process([
            $binary,
            '--http-addr', "127.0.0.1:{$port}",
            '--master-key', self::MASTER_KEY,
            '--db-path', $this->dataDir,
            '--no-analytics',
        ]);
        $this->meilisearch->start();

        $this->client = new Client("http://127.0.0.1:{$port}", self::MASTER_KEY);
        $this->waitForHealth();

        $this->indexer = new AnimeSearchIndexer($this->client);
        $this->entityManager = $this->createEntityManager();
    }

    protected function tearDown(): void
    {
        $this->meilisearch?->stop();

        if (isset($this->dataDir) && is_dir($this->dataDir)) {
            (new Process(['rm', '-rf', $this->dataDir]))->run();
        }
    }

    public function testConfigureIndexIsIdempotentAndAppliesTheSchema(): void
    {
        $this->indexer->configureIndex();
        $this->indexer->configureIndex();

        $settings = $this->client->index('anime')->getSettings();

        $this->assertSame(['title', 'names'], $settings['searchableAttributes']);
        $this->assertEqualsCanonicalizing(
            ['genres', 'themes', 'demographic', 'watch_status', 'studios', 'labels'],
            $settings['filterableAttributes'],
        );
        $this->assertSame(['имени', 'именем'], $settings['synonyms']['имя']);
        $this->assertSame(6, $settings['typoTolerance']['minWordSizeForTypos']['twoTypos']);
    }

    public function testIndexMakesTitleGenresAndAlternateNamesSearchable(): void
    {
        $this->indexer->configureIndex();

        $studio = new Studio();
        $studio->rename('Bones');
        $label = new Label('Favorites');

        $anime = new MovieAnime();
        $anime->setTitle('Твоё имя')
            ->setDemographic(Demographic::Shounen)
            ->setWatchStatus(WatchStatus::Plan)
            ->addGenre(GenreCode::Drama)
            ->addTheme(ThemeCode::Isekai)
            ->addStudio($studio)
            ->addLabel($label)
            ->addName('Kimi no Na wa', AnimeNameType::English);

        $this->entityManager->persist($studio);
        $this->entityManager->persist($label);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->indexer->index($anime);

        $index = $this->client->index('anime');

        $byTitle = $index->search('Твоё имя');
        $this->assertSame([$anime->id], array_column($byTitle->getHits(), 'id'));

        // "имени" is not reachable from "имя" through typo-tolerance alone (verified
        // empirically — see context/tech_decisions.md); only the synonym dictionary bridges it.
        $byInflectedForm = $index->search('имени', ['matchingStrategy' => 'frequency']);
        $this->assertSame([$anime->id], array_column($byInflectedForm->getHits(), 'id'));

        $byAlternateName = $index->search('Kimi no Na wa');
        $this->assertSame([$anime->id], array_column($byAlternateName->getHits(), 'id'));

        $byGenreFacet = $index->search(null, ['filter' => 'genres = "drama"']);
        $this->assertSame([$anime->id], array_column($byGenreFacet->getHits(), 'id'));

        $byStudioFacet = $index->search(null, ['filter' => 'studios = "Bones"']);
        $this->assertSame([$anime->id], array_column($byStudioFacet->getHits(), 'id'));
    }

    public function testDeleteRemovesTheDocumentFromTheIndex(): void
    {
        $this->indexer->configureIndex();

        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $this->indexer->index($anime);
        $this->indexer->delete($anime->id ?? throw new \LogicException('entity id must be set after persisting'));

        $hits = $this->client->index('anime')->search('Cowboy Bebop')->getHits();

        $this->assertSame([], $hits);
    }

    private function waitForHealth(): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if ($this->client->isHealthy()) {
                return;
            }
            usleep(100_000);
        }

        self::fail('Meilisearch did not become healthy in time.');
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (false === $socket) {
            self::fail("Could not find a free port: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (false === $name) {
            self::fail('Could not determine the bound port.');
        }

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function createEntityManager(): EntityManager
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
        $entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}
