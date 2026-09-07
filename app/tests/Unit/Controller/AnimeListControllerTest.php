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

namespace App\Tests\Unit\Controller;

use App\Controller\AnimeListController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Functional test for the anime list endpoint (issue #74): drives the real controller,
 * repository and a real SQLite-backed EntityManager end to end, and checks that "total"
 * (used to compute the page count) stays correct once a filter narrows the result set —
 * the exact failure mode the issue warns about (total and select drifting apart).
 */
final class AnimeListControllerTest extends TestCase
{
    private EntityManager $entityManager;
    private AnimeListController $controller;

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

        $repository = new AnimeRepository($this->entityManager);

        // No test in this file sets "name" (that would need the anime_fts virtual table, see
        // AnimeRepositoryTest), so a resolver stub that never resolves anything is enough here
        // — the search-specific wiring is covered by AnimeListSearchControllerTest.
        $searchResolver = $this->createStub(AnimeSearchResolver::class);
        $searchResolver->method('tryResolveIds')->willReturn(null);

        $configPath = sys_get_temp_dir().'/anime-config-test-'.uniqid().'.json';
        $this->controller = new AnimeListController(
            $repository,
            new AnimeListRequestParser(),
            new AnimeListSortResolver(),
            new AppSettingsProvider(new AppConfigStore($configPath)),
            $searchResolver,
        );

        for ($i = 1; $i <= 5; ++$i) {
            $tv = new TvAnime();
            $tv->setTitle("TV Show {$i}")->setWatchStatus(WatchStatus::Watching);
            $tv->addGenre(GenreCode::Action);
            $this->entityManager->persist($tv);
        }

        $movie = new MovieAnime();
        $movie->setTitle('The Movie')->setWatchStatus(WatchStatus::Watching);
        $movie->addGenre(GenreCode::Comedy);
        $this->entityManager->persist($movie);

        $planned = new TvAnime();
        $planned->setTitle('Not Watching Yet')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($planned);

        $this->entityManager->flush();
    }

    public function testListsTheWholeCatalogWhenWatchStatusIsMissing(): void
    {
        $response = $this->controller->list(new Request());
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(7, $data['total']);
        $this->assertCount(7, $data['items']);
    }

    public function testListsOnlyAnimeMatchingWatchStatus(): void
    {
        $response = $this->controller->list(new Request(['watch_status' => 'watching']));
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(6, $data['total']);
        $this->assertCount(6, $data['items']);
    }

    public function testPageCountStaysCorrectWhenAGenreFilterIsActive(): void
    {
        // Only the 5 "TV Show N" rows have GenreCode::Action; the movie and the Plan row must not count.
        $response = $this->controller->list(new Request([
            'watch_status' => 'watching',
            'genres' => [GenreCode::Action->value],
            'limit' => '2',
            'offset' => '0',
        ]));
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame(5, $data['total']);
        $this->assertCount(2, $data['items']);
        $this->assertSame(2, $data['limit']);
        $this->assertSame(0, $data['offset']);

        $expectedPages = (int) ceil($data['total'] / $data['limit']);
        $this->assertSame(3, $expectedPages);
    }

    public function testSerializesLabelNamesForListCard(): void
    {
        $label = new Label('Family favourite');
        $this->entityManager->persist($label);

        $movie = new MovieAnime();
        $movie->setTitle('Labelled Movie')->setWatchStatus(WatchStatus::Watching)->addLabel($label);
        $this->entityManager->persist($movie);
        $this->entityManager->flush();

        $response = $this->controller->list(new Request(['watch_status' => 'watching']));
        $data = json_decode((string) $response->getContent(), true);

        $labelled = array_values(array_filter($data['items'], static fn (array $item): bool => $item['title'] === 'Labelled Movie'));
        $this->assertSame(['Family favourite'], $labelled[0]['labels']);
    }

    public function testDefaultsToInfiniteScrollPaginationMode(): void
    {
        $response = $this->controller->list(new Request(['watch_status' => 'watching']));
        $data = json_decode((string) $response->getContent(), true);

        $this->assertSame('infinite_scroll', $data['pagination_mode']);
    }

    public function testRejectsUnknownEnumValue(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->controller->list(new Request(['watch_status' => 'not-a-real-status']));
    }
}
