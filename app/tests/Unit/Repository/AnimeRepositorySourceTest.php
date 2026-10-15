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
use App\Entity\Anime;
use App\Entity\AnimeSource;
use App\Entity\Enum\AnimeSortField;
use App\Entity\Enum\SortDirection;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\AnimeListFilter;
use App\Repository\AnimeListSort;
use App\Repository\AnimeRepository;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The `source` catalog filter and the per-link entry counter (issue #1054) against a real
 * EntityManager/SQLite connection. Normalized links are set directly via
 * AnimeSource::useNormalizedUrl(): computing them is not what is under test here.
 */
final class AnimeRepositorySourceTest extends TestCase
{
    private EntityManager $entityManager;
    private AnimeRepository $repository;
    private SqlStatementCounter $sql;

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

        $this->sql = new SqlStatementCounter();
        $dbalConfig = new Configuration();
        $dbalConfig->setMiddlewares([new LoggingMiddleware($this->sql)]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $dbalConfig);
        $this->entityManager = new EntityManager($connection, $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->repository = new AnimeRepository($this->entityManager);
    }

    /** @param array<string, string> $sources raw url => normalized url */
    private function persistAnime(string $title, array $sources, WatchStatus $status = WatchStatus::Plan): void
    {
        $anime = new TvAnime();
        $anime->setTitle($title)->setWatchStatus($status);
        foreach ($sources as $url => $normalized) {
            $source = new AnimeSource($anime, $url);
            $source->useNormalizedUrl($normalized);
            $this->entityManager->persist($source);
            $anime->getSources()->add($source);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
    }

    /** @return list<string> */
    private function titlesOf(AnimeListFilter $filter): array
    {
        $this->entityManager->clear();
        $items = $this->repository->findByFilter($filter, new AnimeListSort(AnimeSortField::Name, SortDirection::Asc), 100, 0);

        return array_map(static fn (Anime $a): string => $a->getTitle(), $items);
    }

    public function testSourceFilterReturnsExactlyEntriesWithThatNormalizedUrl(): void
    {
        $this->persistAnime('A', ['https://Example.com/a?x=1' => 'https://example.com/a']);
        $this->persistAnime('B', ['https://example.com/a/' => 'https://example.com/a', 'https://other.example/b' => 'https://other.example/b']);
        $this->persistAnime('C', ['https://example.com/a-other' => 'https://example.com/a-other']);
        $this->persistAnime('D', ['https://example.com/ab' => 'https://example.com/ab']);

        $filter = new AnimeListFilter(source: 'https://example.com/a');

        $this->assertSame(['A', 'B'], $this->titlesOf($filter));
        $this->assertSame(2, $this->repository->countByFilter($filter));
    }

    public function testSourceFilterDoesNotDuplicateAnEntryWhoseSourcesNormalizeToTheSameValue(): void
    {
        $this->persistAnime('A', ['https://example.com/a' => 'https://example.com/a', 'https://example.com/a/' => 'https://example.com/a']);

        $filter = new AnimeListFilter(source: 'https://example.com/a');

        $this->assertSame(['A'], $this->titlesOf($filter));
        $this->assertSame(1, $this->repository->countByFilter($filter));
    }

    public function testSourceFilterCombinesWithOtherFilters(): void
    {
        $this->persistAnime('A', ['https://example.com/a' => 'https://example.com/a']);

        $this->assertSame([], $this->titlesOf(new AnimeListFilter(source: 'https://example.com/a', country: 'JP')));
    }

    public function testFacetsCarryNoSourceFacet(): void
    {
        $this->persistAnime('A', ['https://example.com/a' => 'https://example.com/a'], WatchStatus::Plan);
        $this->persistAnime('B', ['https://example.com/b' => 'https://example.com/b'], WatchStatus::Watching);

        $facets = $this->repository->facetsByFilter(new AnimeListFilter(source: 'https://example.com/a'));

        $this->assertSame(
            ['catalogTotal', 'watchStatuses', 'types', 'datePremiereDecades', 'userRatings', 'labels', 'genres', 'themes', 'studios'],
            array_keys(get_object_vars($facets)),
        );
        $this->assertSame(2, $facets->catalogTotal);
        // the source filter narrows the buckets: only entry A's status remains
        $this->assertCount(1, $facets->watchStatuses);
        $this->assertSame('plan', $facets->watchStatuses[0]->value);
        $this->assertSame(1, $facets->watchStatuses[0]->count);
    }

    public function testCountBySourceNormalizedUrlsCountsEntriesNotLinks(): void
    {
        $this->persistAnime('A', ['https://example.com/a' => 'https://example.com/a', 'https://example.com/a/' => 'https://example.com/a', 'https://example.com/solo' => 'https://example.com/solo']);
        $this->persistAnime('B', ['https://example.com/a?x' => 'https://example.com/a']);

        $counts = $this->repository->countBySourceNormalizedUrls(['https://example.com/a', 'https://example.com/solo', 'https://example.com/none', '']);

        $this->assertSame(['https://example.com/a' => 2, 'https://example.com/solo' => 1], $counts);
    }

    public function testCountBySourceNormalizedUrlsIsOneQueryRegardlessOfTheNumberOfLinks(): void
    {
        $urls = [];
        for ($i = 0; $i < 12; ++$i) {
            $urls[] = 'https://example.com/'.$i;
        }
        $this->persistAnime('A', array_combine($urls, $urls));

        $before = $this->sql->count;
        $this->repository->countBySourceNormalizedUrls($urls);
        $oneLink = $this->sql->count;
        $this->repository->countBySourceNormalizedUrls([$urls[0]]);

        $this->assertSame(1, $oneLink - $before);
        $this->assertSame(1, $this->sql->count - $oneLink);
    }

    public function testCountBySourceNormalizedUrlsRunsNoQueryWithoutLinks(): void
    {
        $before = $this->sql->count;
        $this->assertSame([], $this->repository->countBySourceNormalizedUrls(['']));

        $this->assertSame($before, $this->sql->count);
    }
}

/** Counts statements the DBAL logging middleware reports as executed. */
final class SqlStatementCounter extends AbstractLogger
{
    public int $count = 0;

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (str_starts_with((string) $message, 'Executing')) {
            ++$this->count;
        }
    }
}
