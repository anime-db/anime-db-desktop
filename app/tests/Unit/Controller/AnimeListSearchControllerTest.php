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

namespace App\Tests\Unit\Controller;

use App\Controller\AnimeListController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Service\AnimeListRequestParser;
use App\Service\AnimeListSortResolver;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Search\AnimeSearchResolver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises the anime list search box wiring end to end (issue #199): AnimeListController
 * asks AnimeSearchResolver to resolve the "name" query param via Meilisearch first, and only
 * falls back to the FTS5 quick-filter (issue #195, already covered by AnimeRepositoryTest)
 * when the resolver reports Meilisearch as unreachable (returns null). AnimeListControllerTest
 * intentionally never sets "name" (no anime_fts table there); this file sets it up the same
 * way AnimeRepositoryTest does, precisely to exercise both branches.
 */
final class AnimeListSearchControllerTest extends TestCase
{
    private EntityManager $entityManager;
    private int $trigunId;

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
        $this->createAnimeFtsSchema();

        $trigun = new TvAnime();
        $trigun->setTitle('Trigun')->setWatchStatus(WatchStatus::Watching);
        $this->entityManager->persist($trigun);

        $cowboyBebop = new TvAnime();
        $cowboyBebop->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Watching);
        $this->entityManager->persist($cowboyBebop);

        $this->entityManager->flush();

        $this->trigunId = $trigun->id ?? throw new \LogicException('entity id must be set after flush');
    }

    public function testUsesMeilisearchResolvedIdsWhenAvailable(): void
    {
        // Even though "Cowboy Bebop" also matches FTS5-style prefix search, a resolved
        // Meilisearch hit list must be used as-is and must not be widened by FTS5 too.
        $controller = $this->createController($this->stubResolver([$this->trigunId]));

        $response = $controller->list(new Request(['watch_status' => 'watching', 'name' => 'Trigun']));
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(1, $data['total']);
        $this->assertSame('Trigun', $data['items'][0]['title']);
    }

    public function testFallsBackToFts5QuickFilterWhenMeilisearchIsUnavailable(): void
    {
        $controller = $this->createController($this->stubResolver(null));

        $response = $controller->list(new Request(['watch_status' => 'watching', 'name' => 'Trigun']));
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(1, $data['total']);
        $this->assertSame('Trigun', $data['items'][0]['title']);
    }

    public function testFallbackFindsNothingForANameThatDoesNotMatchAnyTitle(): void
    {
        $controller = $this->createController($this->stubResolver(null));

        $response = $controller->list(new Request(['watch_status' => 'watching', 'name' => 'No Such Anime']));
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(0, $data['total']);
    }

    /**
     * @param list<int>|null $ids
     */
    private function stubResolver(?array $ids): AnimeSearchResolver
    {
        $resolver = $this->createStub(AnimeSearchResolver::class);
        $resolver->method('tryResolveIds')->willReturn($ids);

        return $resolver;
    }

    private function createController(AnimeSearchResolver $searchResolver): AnimeListController
    {
        $configPath = sys_get_temp_dir().'/anime-config-test-'.uniqid().'.json';

        return new AnimeListController(
            new AnimeRepository($this->entityManager),
            new AnimeListRequestParser(),
            new AnimeListSortResolver(),
            new AppSettingsProvider(new AppConfigStore($configPath)),
            $searchResolver,
        );
    }

    /**
     * Same anime_fts virtual table/triggers as AnimeRepositoryTest — SchemaTool has no
     * concept of FTS5 virtual tables, so the raw-SQL migration (Version20260713000000,
     * issue #195) has to be reproduced by hand here too.
     */
    private function createAnimeFtsSchema(): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement('CREATE VIRTUAL TABLE anime_fts USING fts5(name, anime_id UNINDEXED)');

        $connection->executeStatement('
            CREATE TRIGGER anime_fts_ai_anime AFTER INSERT ON anime BEGIN
                INSERT INTO anime_fts(rowid, anime_id, name) VALUES (new.id, new.id, new.title);
            END
        ');
        $connection->executeStatement('
            CREATE TRIGGER anime_fts_au_anime AFTER UPDATE OF title ON anime BEGIN
                UPDATE anime_fts SET name = new.title WHERE rowid = new.id;
            END
        ');
        $connection->executeStatement('
            CREATE TRIGGER anime_fts_ad_anime AFTER DELETE ON anime BEGIN
                DELETE FROM anime_fts WHERE rowid = old.id;
            END
        ');

        $connection->executeStatement('
            CREATE TRIGGER anime_fts_ai_anime_name AFTER INSERT ON anime_name BEGIN
                INSERT INTO anime_fts(rowid, anime_id, name) VALUES (-new.id, new.anime_id, new.name);
            END
        ');
        $connection->executeStatement('
            CREATE TRIGGER anime_fts_au_anime_name AFTER UPDATE OF name ON anime_name BEGIN
                UPDATE anime_fts SET name = new.name WHERE rowid = -new.id;
            END
        ');
        $connection->executeStatement('
            CREATE TRIGGER anime_fts_ad_anime_name AFTER DELETE ON anime_name BEGIN
                DELETE FROM anime_fts WHERE rowid = -old.id;
            END
        ');
    }
}
