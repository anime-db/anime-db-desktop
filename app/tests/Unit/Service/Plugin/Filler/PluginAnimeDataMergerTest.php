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

namespace App\Tests\Unit\Service\Plugin\Filler;

use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Model\AnimeName as ContractsAnimeName;
use AnimeDb\PluginContracts\Model\Demographic as ContractsDemographic;
use AnimeDb\PluginContracts\Model\GenreCode as ContractsGenreCode;
use AnimeDb\PluginContracts\Model\NameRole as ContractsNameRole;
use AnimeDb\PluginContracts\Model\ThemeCode as ContractsThemeCode;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\TvAnime;
use App\Repository\StudioRepository;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Real EntityManager/SQLite connection (same setup as ScanStorageServiceTest): applyStudios()
 * find-or-creates Studio rows through StudioRepository, which is not meaningfully mockable
 * without re-implementing its query.
 */
final class PluginAnimeDataMergerTest extends TestCase
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

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 5).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    /** @param array<string, string|null> $downloads url => returned filename (or null for a failed download) */
    private function newMerger(array $downloads = [], ?LoggerInterface $logger = null): PluginAnimeDataMerger
    {
        $downloader = $this->createStub(PluginMediaDownloaderInterface::class);
        $downloader->method('download')->willReturnCallback(
            static fn (int $animeId, string $url): ?string => $downloads[$url] ?? null,
        );

        return new PluginAnimeDataMerger(
            new StudioRepository($this->entityManager),
            $this->entityManager,
            $downloader,
            $logger ?? new NullLogger(),
        );
    }

    private function newAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Placeholder')->setWatchStatus(WatchStatus::Plan);

        return $anime;
    }

    private function persistAndFlush(\App\Entity\Anime $anime): void
    {
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
    }

    public function testApplyOverwritesTitle(): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(title: 'Bleach: Memories of Nobody');

        $unapplied = $this->newMerger()->apply($anime, $data, ['title']);

        $this->assertSame('Bleach: Memories of Nobody', $anime->getTitle());
        $this->assertSame([], $unapplied);
    }

    public function testApplyUnionsAlternativeNamesWithoutDuplicatingExisting(): void
    {
        $anime = $this->newAnime();
        $anime->addName('Existing Synonym', null, AnimeNameRole::Synonym);

        $data = new PluginAnimeData(
            title: 'Bleach',
            alternativeNames: [
                new ContractsAnimeName('Existing Synonym', null, ContractsNameRole::Synonym),
                new ContractsAnimeName('New Synonym', null, ContractsNameRole::Synonym),
            ],
        );

        $this->newMerger()->apply($anime, $data, ['alternativeNames']);

        $names = array_map(static fn ($n): string => $n->name, $anime->getNames()->toArray());
        $this->assertSame(['Existing Synonym', 'New Synonym'], $names);
    }

    /**
     * The dedup key is (normalizedName, locale), not normalizedName alone (issue #724) — two
     * names with identical text but different locales must coexist, e.g. one official title
     * per language.
     */
    public function testApplyKeepsNamesWithTheSameTextInDifferentLocales(): void
    {
        $anime = $this->newAnime();
        $anime->addName('Frieren', 'en', AnimeNameRole::Official);

        $data = new PluginAnimeData(
            title: 'Frieren',
            alternativeNames: [new ContractsAnimeName('Frieren', 'de', ContractsNameRole::Official)],
        );

        $this->newMerger()->apply($anime, $data, ['alternativeNames']);

        $locales = array_map(static fn ($n) => $n->locale, $anime->getNames()->toArray());
        $this->assertSame(['en', 'de'], $locales);
    }

    public function testApplyDoesNotDuplicateAnExactNormalizedNameAndLocalePairAlreadyPresent(): void
    {
        $anime = $this->newAnime();
        $anime->addName('Bleach', 'en', AnimeNameRole::Official);

        $data = new PluginAnimeData(
            title: 'Bleach',
            alternativeNames: [new ContractsAnimeName('bleach', 'en', ContractsNameRole::Official)],
        );

        $this->newMerger()->apply($anime, $data, ['alternativeNames']);

        $this->assertCount(1, $anime->getNames());
    }

    /**
     * A catalog row whose locale is still unknown (null) yields to an incoming pair for the
     * same text that carries a known locale — the "нулевая локаль уступает известной" rule.
     */
    public function testApplyReplacesANullLocaleEntryWhenTheIncomingPairHasAKnownLocale(): void
    {
        $anime = $this->newAnime();
        $anime->addName('Solo Leveling', null, AnimeNameRole::Synonym);

        $data = new PluginAnimeData(
            title: 'Solo Leveling',
            alternativeNames: [new ContractsAnimeName('Solo Leveling', 'en', ContractsNameRole::Official)],
        );

        $this->newMerger()->apply($anime, $data, ['alternativeNames']);

        // array_values(): the evicted entry leaves a gap in the collection's internal keys,
        // so the surviving row is not necessarily at index 0.
        $names = array_values($anime->getNames()->toArray());
        $this->assertCount(1, $names);
        $this->assertSame('en', $names[0]->locale);
        $this->assertSame(AnimeNameRole::Official, $names[0]->role);
    }

    /**
     * The reverse direction of the null-locale rule: an already-typed row must never be
     * touched by the filler, even when the incoming pair for the same text has no locale.
     */
    public function testApplyLeavesAnAlreadyTypedEntryUntouchedWhenTheIncomingPairHasNoLocale(): void
    {
        $anime = $this->newAnime();
        $anime->addName('Solo Leveling', 'en', AnimeNameRole::Official);

        $data = new PluginAnimeData(
            title: 'Solo Leveling',
            alternativeNames: [new ContractsAnimeName('Solo Leveling', null, ContractsNameRole::Synonym)],
        );

        $this->newMerger()->apply($anime, $data, ['alternativeNames']);

        $names = $anime->getNames()->toArray();
        $this->assertCount(2, $names);
        $this->assertSame('en', $names[0]->locale);
        $this->assertSame(AnimeNameRole::Official, $names[0]->role);
        $this->assertNull($names[1]->locale);
        $this->assertSame(AnimeNameRole::Synonym, $names[1]->role);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideRawLocales(): iterable
    {
        yield 'regional subtag is stripped' => ['ru-RU', 'ru'];
        yield 'uppercase is lowercased' => ['RU', 'ru'];
        yield 'not a language subtag becomes null' => ['russian', null];
    }

    #[DataProvider('provideRawLocales')]
    public function testApplyNormalizesTheIncomingLocale(string $rawLocale, ?string $expectedLocale): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(
            title: 'Bleach',
            alternativeNames: [new ContractsAnimeName('Bleach', $rawLocale, ContractsNameRole::Official)],
        );

        $this->newMerger()->apply($anime, $data, ['alternativeNames']);

        $this->assertSame($expectedLocale, $anime->getNames()->toArray()[0]->locale);
    }

    /**
     * The dedup key is built from the *normalized* locale (see applyAlternativeNames()'s
     * $existingByKey/$key), not the raw incoming one. An existing 'ru' row and an incoming
     * 'ru-RU'/'RU' pair for the same text must collide into a single row - if the merger ever
     * stopped normalizing before keying, this would regress into a duplicate even though
     * testApplyNormalizesTheIncomingLocale() (which only checks the persisted ->locale, already
     * normalized by AnimeName::__construct() regardless of what the merger does) stays green.
     */
    public function testApplyDeduplicatesAgainstAnExistingEntryByNormalizedLocale(): void
    {
        $anime = $this->newAnime();
        $anime->addName('Bleach', 'ru', AnimeNameRole::Official);

        $data = new PluginAnimeData(
            title: 'Bleach',
            alternativeNames: [new ContractsAnimeName('Bleach', 'ru-RU', ContractsNameRole::Official)],
        );

        $this->newMerger()->apply($anime, $data, ['alternativeNames']);

        $names = $anime->getNames()->toArray();
        $this->assertCount(1, $names);
        $this->assertSame('ru', $names[0]->locale);
    }

    public function testApplyMergesDescriptionsByLocaleWithoutLosingOtherLocales(): void
    {
        $anime = $this->newAnime();
        $anime->setDescription('en', 'English summary');

        $data = new PluginAnimeData(title: 'Bleach', descriptions: ['ru' => 'Русское описание']);

        $this->newMerger()->apply($anime, $data, ['descriptions']);

        $this->assertSame('English summary', $anime->getSummary('en'));
        $this->assertSame('Русское описание', $anime->getSummary('ru'));
    }

    public function testApplyUnionsGenresMappedFromContractsEnum(): void
    {
        $anime = $this->newAnime();
        $anime->addGenre(GenreCode::Comedy);

        $data = new PluginAnimeData(title: 'Bleach', genres: [ContractsGenreCode::Action, ContractsGenreCode::Comedy]);

        $this->newMerger()->apply($anime, $data, ['genres']);

        $this->assertSame([GenreCode::Comedy, GenreCode::Action], $anime->getGenreCodes());
    }

    public function testApplyUnionsThemesMappedFromContractsEnum(): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(title: 'Bleach', themes: [ContractsThemeCode::Isekai]);

        $this->newMerger()->apply($anime, $data, ['themes']);

        $this->assertSame([ThemeCode::Isekai], $anime->getThemeCodes());
    }

    public function testApplyOverwritesDemographic(): void
    {
        $anime = $this->newAnime();
        $anime->setDemographic(Demographic::Kids);

        $data = new PluginAnimeData(title: 'Bleach', demographic: ContractsDemographic::Shounen);

        $this->newMerger()->apply($anime, $data, ['demographic']);

        $this->assertSame(Demographic::Shounen, $anime->getDemographic());
    }

    public function testApplyUnionsStudiosCreatingStudioOnFirstUseAndReusingItOnSecondCall(): void
    {
        $data = new PluginAnimeData(title: 'Bleach', studios: ['Studio Pierrot']);
        $merger = $this->newMerger();

        $first = $this->newAnime();
        $merger->apply($first, $data, ['studios']);
        $this->entityManager->persist($first);
        $this->entityManager->flush();

        $second = $this->newAnime();
        $merger->apply($second, $data, ['studios']);

        $this->assertSame(
            $first->getStudios()->toArray()[0]->id,
            $second->getStudios()->toArray()[0]->id,
        );
    }

    public function testApplyReusesNewlyCreatedStudioAcrossCallsBeforeFlush(): void
    {
        $data = new PluginAnimeData(title: 'Bleach', studios: ['Studio Pierrot']);
        $merger = $this->newMerger();

        $first = $this->newAnime();
        $merger->apply($first, $data, ['studios']);
        $this->entityManager->persist($first);

        $second = $this->newAnime();
        $merger->apply($second, $data, ['studios']);
        $this->entityManager->persist($second);

        $this->entityManager->flush();

        $this->assertSame(
            $first->getStudios()->toArray()[0]->id,
            $second->getStudios()->toArray()[0]->id,
        );
        $this->assertCount(1, $this->entityManager->getRepository(\App\Entity\Studio::class)->findAll());
    }

    public function testApplyUnionsCountriesWithoutDuplicates(): void
    {
        $anime = $this->newAnime();
        $anime->setCountries(['JP']);

        $data = new PluginAnimeData(title: 'Bleach', countries: ['JP', 'US']);

        $this->newMerger()->apply($anime, $data, ['countries']);

        $this->assertSame(['JP', 'US'], $anime->getCountries());
    }

    public function testApplyOverwritesScalarDateAndDurationFields(): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(
            title: 'Bleach',
            datePremiere: new \DateTimeImmutable('2004-10-05'),
            dateEnd: new \DateTimeImmutable('2005-03-27'),
            durationMinutes: 24,
        );

        $this->newMerger()->apply($anime, $data, ['datePremiere', 'dateEnd', 'durationMinutes']);

        $this->assertEquals(new \DateTimeImmutable('2004-10-05'), $anime->getDatePremiere());
        $this->assertEquals(new \DateTimeImmutable('2005-03-27'), $anime->getDateEnd());
        $this->assertSame(24, $anime->getDurationMinutes());
    }

    public function testApplyLeavesFieldUntouchedWhenPluginDataIsNull(): void
    {
        $anime = $this->newAnime();
        $anime->setDurationMinutes(24);

        $data = new PluginAnimeData(title: 'Bleach', durationMinutes: null);

        $this->newMerger()->apply($anime, $data, ['durationMinutes']);

        $this->assertSame(24, $anime->getDurationMinutes());
    }

    /**
     * Issue #860, scenario 3: a stored datePremiere that conflicts with the incoming dateEnd
     * alone must not throw out of apply(), must leave dateEnd unchanged, must log a warning, and
     * must not appear in the returned unapplied list — a non-empty unapplied turns into
     * FillResult::ImageRejected downstream, which would misreport a date conflict as a rejected
     * image.
     */
    public function testApplyRejectsDateEndConflictingWithStoredDatePremiereWithoutThrowingAndLogsAWarning(): void
    {
        $anime = $this->newAnime();
        $anime->setDatePremiere(new \DateTimeImmutable('2020-06-01'));

        $data = new PluginAnimeData(title: 'Bleach', dateEnd: new \DateTimeImmutable('2020-01-01'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->isType('string'),
            $this->callback(static fn (array $context): bool => $context['sourceDateEnd']->format('Y-m-d') === '2020-01-01'
                && $context['storedDatePremiere']->format('Y-m-d') === '2020-06-01'),
        );

        $unapplied = $this->newMerger(logger: $logger)->apply($anime, $data, ['dateEnd']);

        $this->assertNull($anime->getDateEnd());
        $this->assertEquals(new \DateTimeImmutable('2020-06-01'), $anime->getDatePremiere());
        $this->assertSame([], $unapplied);
    }

    /**
     * Issue #860, scenario 4: the mirror case, a new datePremiere from the source that conflicts
     * with the already-stored dateEnd — datePremiere must stay untouched, no exception.
     */
    public function testApplyRejectsDatePremiereConflictingWithStoredDateEndWithoutThrowing(): void
    {
        $anime = $this->newAnime();
        $anime->setDateEnd(new \DateTimeImmutable('2020-01-01'));

        $data = new PluginAnimeData(title: 'Bleach', datePremiere: new \DateTimeImmutable('2020-06-01'));

        $unapplied = $this->newMerger()->apply($anime, $data, ['datePremiere']);

        $this->assertNull($anime->getDatePremiere());
        $this->assertEquals(new \DateTimeImmutable('2020-01-01'), $anime->getDateEnd());
        $this->assertSame([], $unapplied);
    }

    /**
     * Issue #860, scenario 5: the source's own pair is internally invalid (both dates present in
     * $fields, nothing stored yet) — neither date is applied, but every other field in $fields is
     * applied as usual; the date rejection does not take the rest of the call down with it.
     */
    public function testApplyRejectsAnInvalidPairFromDataAloneButStillAppliesOtherFields(): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(
            title: 'Bleach',
            datePremiere: new \DateTimeImmutable('2020-06-01'),
            dateEnd: new \DateTimeImmutable('2020-01-01'),
            durationMinutes: 24,
        );

        $unapplied = $this->newMerger()->apply($anime, $data, ['datePremiere', 'dateEnd', 'durationMinutes']);

        $this->assertNull($anime->getDatePremiere());
        $this->assertNull($anime->getDateEnd());
        $this->assertSame(24, $anime->getDurationMinutes());
        $this->assertSame([], $unapplied);
    }

    /**
     * Issue #860, scenario 7: the pair's outcome must not depend on whether 'datePremiere' or
     * 'dateEnd' comes first in $fields — both orderings reach the same (rejected) result here.
     */
    public function testApplyDatePairRejectionResultIsIndependentOfFieldOrder(): void
    {
        $data = new PluginAnimeData(
            title: 'Bleach',
            datePremiere: new \DateTimeImmutable('2020-06-01'),
            dateEnd: new \DateTimeImmutable('2020-01-01'),
        );

        $forward = $this->newAnime();
        $this->newMerger()->apply($forward, $data, ['datePremiere', 'dateEnd']);

        $backward = $this->newAnime();
        $this->newMerger()->apply($backward, $data, ['dateEnd', 'datePremiere']);

        $this->assertNull($forward->getDatePremiere());
        $this->assertNull($forward->getDateEnd());
        $this->assertNull($backward->getDatePremiere());
        $this->assertNull($backward->getDateEnd());
    }

    public function testApplyOverwritesEpisodesCountOnSeriesAnime(): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(title: 'Bleach', episodesCount: 366);

        $this->newMerger()->apply($anime, $data, ['episodesCount']);

        $this->assertSame(366, $anime->getEpisodesCount());
    }

    public function testApplyIgnoresEpisodesCountOnMovieAnime(): void
    {
        $anime = new MovieAnime();
        $anime->setTitle('Placeholder')->setWatchStatus(WatchStatus::Plan);

        $data = new PluginAnimeData(title: 'Bleach', episodesCount: 1);

        // MovieAnime has no setEpisodesCount() at all — reaching for it would be a fatal error,
        // so simply not throwing here already proves the field was skipped.
        $this->newMerger()->apply($anime, $data, ['episodesCount']);

        $this->addToAssertionCount(1);
    }

    public function testApplyOverwritesCoverThroughMediaDownloader(): void
    {
        $anime = $this->newAnime();
        $this->persistAndFlush($anime);

        $data = new PluginAnimeData(title: 'Bleach', cover: 'https://example.test/cover.jpg');

        $unapplied = $this->newMerger(['https://example.test/cover.jpg' => 'abc123.jpg'])
            ->apply($anime, $data, ['cover']);

        $this->assertSame('abc123.jpg', $anime->getCover());
        $this->assertSame([], $unapplied);
    }

    /**
     * The download/normalization failure this covers is exactly what issue #507 needed apply()
     * to start reporting: 'cover' lands in the unapplied list precisely because a URL was given
     * and could not be turned into a file, distinguishing it from "the plugin had nothing".
     */
    public function testApplyLeavesCoverUntouchedAndReportsItUnappliedWhenDownloadFails(): void
    {
        $anime = $this->newAnime();
        $anime->setCover('existing.jpg');
        $this->persistAndFlush($anime);

        $data = new PluginAnimeData(title: 'Bleach', cover: 'https://example.test/broken.jpg');

        $unapplied = $this->newMerger()->apply($anime, $data, ['cover']);

        $this->assertSame('existing.jpg', $anime->getCover());
        $this->assertSame(['cover'], $unapplied);
    }

    public function testApplySkipsCoverWhenAnimeIsNotYetPersisted(): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(title: 'Bleach', cover: 'https://example.test/cover.jpg');

        $unapplied = $this->newMerger(['https://example.test/cover.jpg' => 'abc123.jpg'])
            ->apply($anime, $data, ['cover']);

        $this->assertNull($anime->getCover());
        // Not-yet-persisted is a "nothing was attempted" skip, not a rejection - see applyCover()'s docblock.
        $this->assertSame([], $unapplied);
    }

    public function testApplyUnionsImagesThroughMediaDownloaderWithoutDuplicating(): void
    {
        $anime = $this->newAnime();
        $this->persistAndFlush($anime);
        $anime->addImage('existing.jpg');

        $data = new PluginAnimeData(title: 'Bleach', images: [
            'https://example.test/1.jpg',
            'https://example.test/2.jpg',
        ]);

        $unapplied = $this->newMerger([
            'https://example.test/1.jpg' => 'existing.jpg',
            'https://example.test/2.jpg' => 'new.jpg',
        ])->apply($anime, $data, ['images']);

        $sources = array_map(static fn ($image): string => $image->source, $anime->getImages()->toArray());
        $this->assertSame(['existing.jpg', 'new.jpg'], $sources);
        $this->assertSame([], $unapplied);
    }

    /**
     * Partial success (issue #507): one URL downloads, the other does not - 'images' must not
     * appear in the unapplied list, per apply()'s docblock.
     */
    public function testApplyDoesNotReportImagesUnappliedWhenAtLeastOneUrlDownloads(): void
    {
        $anime = $this->newAnime();
        $this->persistAndFlush($anime);

        $data = new PluginAnimeData(title: 'Bleach', images: [
            'https://example.test/1.jpg',
            'https://example.test/broken.jpg',
        ]);

        $unapplied = $this->newMerger([
            'https://example.test/1.jpg' => 'new.jpg',
        ])->apply($anime, $data, ['images']);

        $sources = array_map(static fn ($image): string => $image->source, $anime->getImages()->toArray());
        $this->assertSame(['new.jpg'], $sources);
        $this->assertSame([], $unapplied);
    }

    public function testApplyReportsImagesUnappliedWhenEveryUrlFailsToDownload(): void
    {
        $anime = $this->newAnime();
        $this->persistAndFlush($anime);

        $data = new PluginAnimeData(title: 'Bleach', images: ['https://example.test/broken.jpg']);

        $unapplied = $this->newMerger()->apply($anime, $data, ['images']);

        $this->assertCount(0, $anime->getImages());
        $this->assertSame(['images'], $unapplied);
    }

    public function testApplyDoesNotReportImagesUnappliedWhenTheUrlListIsEmpty(): void
    {
        $anime = $this->newAnime();
        $this->persistAndFlush($anime);

        $data = new PluginAnimeData(title: 'Bleach', images: []);

        $unapplied = $this->newMerger()->apply($anime, $data, ['images']);

        $this->assertSame([], $unapplied);
    }

    public function testApplyIgnoresUnknownFieldNames(): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(title: 'Bleach');

        $unapplied = $this->newMerger()->apply($anime, $data, ['type']);

        $this->assertSame('Placeholder', $anime->getTitle());
        $this->assertSame([], $unapplied);
    }
}
