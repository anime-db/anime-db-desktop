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
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\MovieAnime;
use App\Entity\Studio;
use App\Entity\TvAnime;
use App\Entity\ValueObject\Rating;
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
 * Functional test for GET /anime/facets (issue #666): drives the real controller, repository
 * and a real SQLite-backed EntityManager end to end, following the same setup as
 * AnimeListControllerTest. The fixtures are deliberately built so that "Comedy" and "Drama"
 * overlap on exactly one anime — that overlap is what tells apart a facet counted "by
 * selection" (wrong) from one counted "by filter minus its own section" (what the issue
 * requires): see testGenreFacetIgnoresItsOwnSelectionInsteadOfNarrowingToIt().
 */
final class AnimeFacetsControllerTest extends TestCase
{
    private EntityManager $entityManager;
    private AnimeListController $controller;
    private Studio $sunrise;
    private Studio $toei;

    /** @var array<string, int> anime title => id */
    private array $animeIds = [];

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

        $this->controller = $this->createController($this->stubResolver(null));

        $this->seedFixtures();
    }

    private function seedFixtures(): void
    {
        $this->sunrise = new Studio();
        $this->sunrise->rename('Sunrise');
        $this->toei = new Studio();
        $this->toei->rename('Toei');
        $favorite = new Label('favorite');

        $this->entityManager->persist($this->sunrise);
        $this->entityManager->persist($this->toei);
        $this->entityManager->persist($favorite);

        $tvAction = new TvAnime();
        $tvAction->setTitle('TV Action')
            ->setWatchStatus(WatchStatus::Watching)
            ->setUserRating(new Rating(5))
            ->setDatePremiere(new \DateTimeImmutable('2015-03-01'));
        $tvAction->addGenre(GenreCode::Action)->addStudio($this->sunrise);

        $tvActionComedy = new TvAnime();
        $tvActionComedy->setTitle('TV Action Comedy')
            ->setWatchStatus(WatchStatus::Watching)
            ->setUserRating(new Rating(4))
            ->setDatePremiere(new \DateTimeImmutable('2015-08-01'));
        $tvActionComedy->addGenre(GenreCode::Action)->addGenre(GenreCode::Comedy)
            ->addStudio($this->sunrise)->addLabel($favorite);

        $movieComedy = new MovieAnime();
        $movieComedy->setTitle('Movie Comedy')
            ->setWatchStatus(WatchStatus::Watching)
            ->setUserRating(new Rating(3))
            ->setDatePremiere(new \DateTimeImmutable('2020-05-01'));
        $movieComedy->addGenre(GenreCode::Comedy)->addStudio($this->toei);

        // No rating, premiered before every other fixture (1990s bucket).
        $tvDrama = new TvAnime();
        $tvDrama->setTitle('TV Drama')
            ->setWatchStatus(WatchStatus::Plan)
            ->setDatePremiere(new \DateTimeImmutable('1999-01-01'));
        $tvDrama->addGenre(GenreCode::Drama)->addStudio($this->toei);

        // Comedy AND Drama together — the one anime that would make a "by selection" facet
        // undercount Drama once Comedy is selected. No premiere date (the "none" bucket).
        $tvComedyDrama = new TvAnime();
        $tvComedyDrama->setTitle('TV Comedy Drama')
            ->setWatchStatus(WatchStatus::Watching)
            ->setUserRating(new Rating(2));
        $tvComedyDrama->addGenre(GenreCode::Comedy)->addGenre(GenreCode::Drama)
            ->addTheme(ThemeCode::Mecha)->addStudio($this->toei);

        foreach ([$tvAction, $tvActionComedy, $movieComedy, $tvDrama, $tvComedyDrama] as $anime) {
            $this->entityManager->persist($anime);
        }
        $this->entityManager->flush();

        foreach ([$tvAction, $tvActionComedy, $movieComedy, $tvDrama, $tvComedyDrama] as $anime) {
            $this->animeIds[$anime->getTitle()] = $anime->id ?? throw new \LogicException('id must be set after flush');
        }
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function facetsFor(array $query = []): array
    {
        $response = $this->controller->facets(new Request($query));

        /* @var array<string, mixed> */
        return json_decode((string) $response->getContent(), true);
    }

    /** @param array<int, array{value?: string, id?: int, name?: string, count: int}> $buckets */
    private static function bucketCount(array $buckets, string $key, string|int $value): ?int
    {
        foreach ($buckets as $bucket) {
            if (($bucket[$key] ?? null) === $value) {
                return $bucket['count'];
            }
        }

        return null;
    }

    public function testGenreFacetIgnoresItsOwnSelectionInsteadOfNarrowingToIt(): void
    {
        // "TV Drama" and "TV Comedy Drama" both carry Drama; only the latter also carries
        // Comedy. A facet counted "by selection" would report Drama=1 (the comedy∩drama
        // overlap) once Comedy is selected — the issue requires Drama=2 (every drama in the
        // catalog), because the count must answer "what would clicking Drama add".
        $data = $this->facetsFor(['genres' => [GenreCode::Comedy->value]]);

        $this->assertSame(2, self::bucketCount($data['genres'], 'value', 'drama'));
        $this->assertSame(3, self::bucketCount($data['genres'], 'value', 'comedy'));
        $this->assertSame(2, self::bucketCount($data['genres'], 'value', 'action'));
    }

    public function testGenreFacetUsesAFreshJoinAliasAndIsNotNarrowedToTheSelectedGenre(): void
    {
        // A naive facet built on top of createFilteredQueryBuilder() would reuse the "g"
        // alias already narrowed by "genres IN (:genres)" and GROUP BY would only ever be
        // able to return the selected genre itself.
        $data = $this->facetsFor(['genres' => [GenreCode::Action->value]]);

        $this->assertGreaterThan(1, \count($data['genres']));
    }

    public function testTypeFacetIgnoresItsOwnSelection(): void
    {
        $data = $this->facetsFor(['type' => ['movie']]);

        $this->assertSame(4, self::bucketCount($data['type'], 'value', 'tv'));
        $this->assertSame(1, self::bucketCount($data['type'], 'value', 'movie'));
    }

    public function testWatchStatusFacetIgnoresItsOwnSelection(): void
    {
        $data = $this->facetsFor(['watch_status' => ['watching']]);

        $this->assertSame(4, self::bucketCount($data['watch_status'], 'value', 'watching'));
        $this->assertSame(1, self::bucketCount($data['watch_status'], 'value', 'plan'));
    }

    public function testUserRatingFacetIncludesANoneBucketForUnratedAnime(): void
    {
        $data = $this->facetsFor();

        $this->assertSame(1, self::bucketCount($data['user_rating'], 'value', 'none'));
        $this->assertSame(1, self::bucketCount($data['user_rating'], 'value', '5'));
        $this->assertSame(1, self::bucketCount($data['user_rating'], 'value', '2'));
    }

    public function testDatePremiereFacetBucketsByDecadeAndIncludesANoneBucket(): void
    {
        $data = $this->facetsFor();

        $this->assertSame(2, self::bucketCount($data['date_premiere_decade'], 'value', '2010s'));
        $this->assertSame(1, self::bucketCount($data['date_premiere_decade'], 'value', '2020s'));
        $this->assertSame(1, self::bucketCount($data['date_premiere_decade'], 'value', '1990s'));
        $this->assertSame(1, self::bucketCount($data['date_premiere_decade'], 'value', 'none'));
    }

    public function testStudioFacetIgnoresItsOwnSelectionAndUsesAFreshJoinAlias(): void
    {
        $data = $this->facetsFor(['studios' => [$this->sunrise->id]]);

        $this->assertSame(2, self::bucketCount($data['studios'], 'id', (int) $this->sunrise->id));
        $this->assertSame(3, self::bucketCount($data['studios'], 'id', (int) $this->toei->id));
    }

    public function testThemeFacetIsPopulated(): void
    {
        $data = $this->facetsFor();

        $this->assertSame(1, self::bucketCount($data['themes'], 'value', 'mecha'));
    }

    public function testValuesAbsentFromTheCatalogAreNotReturned(): void
    {
        $data = $this->facetsFor();

        $this->assertNull(self::bucketCount($data['genres'], 'value', 'horror'));
        $this->assertNull(self::bucketCount($data['user_rating'], 'value', '1'));
    }

    public function testFacetsAreRestrictedToTheSearchIntersectionWhenNameIsResolved(): void
    {
        // Only "TV Action" and "Movie Comedy" match — "TV Drama"/"TV Comedy Drama" must not
        // contribute to any facet, exactly like AnimeListController::list()'s own ids.
        $ids = [$this->animeIds['TV Action'], $this->animeIds['Movie Comedy']];
        $this->controller = $this->createController($this->stubResolver($ids));

        $data = $this->facetsFor(['name' => 'whatever']);

        $this->assertSame(1, self::bucketCount($data['genres'], 'value', 'action'));
        $this->assertSame(1, self::bucketCount($data['genres'], 'value', 'comedy'));
        $this->assertNull(self::bucketCount($data['genres'], 'value', 'drama'));
    }

    /** @param list<int>|null $ids */
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
}
