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

namespace App\Tests\Unit\Service\Import\V1;

use App\Entity\Enum\AnimeType;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Import\V1AnimeRecord;
use App\Entity\Import\V1StorageRecord;
use App\Entity\Label;
use App\Entity\Storage;
use App\Entity\Studio;
use App\Repository\LabelRepository;
use App\Repository\StorageRepository;
use App\Repository\StudioRepository;
use App\Service\Import\V1\V1AnimeResolver;
use App\Tests\Support\CreatesInMemoryEntityManager;
use App\Tests\Support\V1DatabaseBuilder;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class V1AnimeResolverTest extends TestCase
{
    use CreatesInMemoryEntityManager;

    private EntityManager $entityManager;
    private V1AnimeResolver $resolver;

    protected function setUp(): void
    {
        $this->entityManager = $this->createInMemoryEntityManager();
        $this->resolver = new V1AnimeResolver(
            $this->entityManager,
            new LabelRepository($this->entityManager),
            new StudioRepository($this->entityManager),
            new StorageRepository($this->entityManager),
        );
    }

    /** @return iterable<string, array{string|null, AnimeType}> */
    public static function typeProvider(): iterable
    {
        yield 'tv' => ['tv', AnimeType::Tv];
        yield 'feature' => ['feature', AnimeType::Movie];
        yield 'featurette' => ['featurette', AnimeType::Movie];
        yield 'ova' => ['ova', AnimeType::Ova];
        yield 'ona' => ['ona', AnimeType::Ona];
        yield 'special' => ['special', AnimeType::Special];
        yield 'music' => ['music', AnimeType::Music];
        yield 'upper case' => ['TV', AnimeType::Tv];
        yield 'unknown' => ['something', AnimeType::Tv];
        yield 'empty' => [null, AnimeType::Tv];
    }

    #[DataProvider('typeProvider')]
    public function testMapsTheV1TypeToAnimeType(?string $type, AnimeType $expected): void
    {
        $this->assertSame($expected, $this->resolver->resolveType(new V1AnimeRecord(1, 'A', type: $type)));
    }

    public function testStatusLabelsAreSplitFromRealLabels(): void
    {
        $record = new V1AnimeRecord(1, 'A', labels: ['Просмотрено', 'Online', 'Скачено']);

        $this->assertSame(WatchStatus::Completed, $this->resolver->resolveWatchStatus($record));
        $this->assertTrue($this->resolver->hasExplicitWatchStatus($record));
        $this->assertSame(['Online', 'Скачено'], array_map(static fn (Label $label): string => $label->name, $this->resolver->resolveLabels($record)));
    }

    public function testWithoutAStatusLabelTheDefaultIsCompleted(): void
    {
        $record = new V1AnimeRecord(1, 'A', labels: ['Online']);

        $this->assertSame(WatchStatus::Completed, $this->resolver->resolveWatchStatus($record));
        $this->assertFalse($this->resolver->hasExplicitWatchStatus($record));
    }

    public function testEveryStatusLabelHasItsStatus(): void
    {
        $expected = [
            'Запланировано' => WatchStatus::Plan,
            'Смотрю' => WatchStatus::Watching,
            'Просмотрено' => WatchStatus::Completed,
            'Отложено' => WatchStatus::OnHold,
            'Брошено' => WatchStatus::Dropped,
        ];
        foreach ($expected as $label => $status) {
            $this->assertSame($status, $this->resolver->resolveWatchStatus(new V1AnimeRecord(1, 'A', labels: [$label])));
        }
    }

    public function testGenreDictionaryCoversAllFiftyV1Genres(): void
    {
        $this->assertCount(50, V1DatabaseBuilder::GENRES);

        $set = $this->resolver->resolveGenres(new V1AnimeRecord(1, 'A', genres: V1DatabaseBuilder::GENRES));

        // Of the 50 names 5 are the 18+ axis and 6 have no counterpart; the other 39 land on an axis.
        $this->assertSame(
            ['Ecchi', 'Erotica', 'Hentai', 'Yaoi', 'Yuri'],
            $this->sorted($set->droppedByDesign),
        );
        $this->assertSame(
            ['Apocalyptic fiction', 'Cyberpunk', 'Fable', 'Magic', 'Police', 'Steampunk'],
            $this->sorted($set->unmapped),
        );
        $this->assertSame(50 - 5 - 6, \count($set->genres) + \count($set->themes) + 1 + \count($set->extraDemographics));
    }

    /** @return iterable<string, array{string, GenreCode|ThemeCode|Demographic}> */
    public static function exceptionProvider(): iterable
    {
        yield 'History' => ['History', ThemeCode::Historical];
        yield 'Thriller' => ['Thriller', GenreCode::Suspense];
        yield 'Sport' => ['Sport', GenreCode::Sports];
        yield 'Demons' => ['Demons', ThemeCode::Mythology];
        yield 'Mahoe shoujo' => ['Mahoe shoujo', ThemeCode::MahouShoujo];
        yield 'Shoujo-ai' => ['Shoujo-ai', GenreCode::GirlsLove];
        yield 'Shounen-ai' => ['Shounen-ai', GenreCode::BoysLove];
        yield 'War' => ['War', ThemeCode::Military];
        yield 'Cars' => ['Cars', ThemeCode::Racing];
        yield 'Game' => ['Game', ThemeCode::StrategyGame];
        yield 'Gender Bender' => ['Gender Bender', ThemeCode::MagicalSexShift];
        yield 'normalised' => ['Sci-fi', GenreCode::SciFi];
        yield 'normalised theme' => ['Super Power', ThemeCode::SuperPower];
        yield 'demographic' => ['Shounen', Demographic::Shounen];
    }

    #[DataProvider('exceptionProvider')]
    public function testResolvesGenreToItsAxis(string $name, GenreCode|ThemeCode|Demographic $expected): void
    {
        $set = $this->resolver->resolveGenres(new V1AnimeRecord(1, 'A', genres: [$name]));

        $actual = match (true) {
            $expected instanceof GenreCode => $set->genres,
            $expected instanceof ThemeCode => $set->themes,
            default => [$set->demographic],
        };
        $this->assertSame([$expected], $actual);
        $this->assertSame([], $set->droppedByDesign);
        $this->assertSame([], $set->unmapped);
    }

    public function testFirstDemographicByGenreOrderIsKept(): void
    {
        $set = $this->resolver->resolveGenres(new V1AnimeRecord(1, 'A', genres: ['Shoujo', 'Shounen']));

        $this->assertSame(Demographic::Shoujo, $set->demographic);
        $this->assertSame(['Shounen'], $set->extraDemographics);
    }

    public function testNamesGetOnlyALanguageAndAreDeduplicated(): void
    {
        $names = $this->resolver->resolveNames(new V1AnimeRecord(1, 'A', names: [
            'BECK　ベック',
            'Blue Literature Series',
            ' Blue Literature Series ',
            'Бек: Восточная Ударная Группа',
            '青い文学シリーズ',
            'D.Gray-man　ディー・グレイマン',
            '',
        ]));

        $this->assertSame(
            [['BECK　ベック', 'ja'], ['Blue Literature Series', null], ['Бек: Восточная Ударная Группа', 'ru'], ['青い文学シリーズ', 'ja'], ['D.Gray-man　ディー・グレイマン', 'ja']],
            array_map(static fn ($name): array => [$name->name, $name->locale], $names),
        );
    }

    public function testNotesJoinEpisodesTranslateAndFileInfo(): void
    {
        $record = new V1AnimeRecord(1, 'A', episodes: '1. Pilot', translate: 'RUS', fileInfo: 'BDRip 720p');

        $this->assertSame("1. Pilot\n\nRUS\n\nBDRip 720p", $this->resolver->resolveNotes($record));
        $this->assertNull($this->resolver->resolveNotes(new V1AnimeRecord(1, 'A', translate: ' ')));
    }

    public function testReusesAnExistingStudioLabelAndStorage(): void
    {
        $studio = new Studio();
        $studio->rename('Madhouse');
        $label = new Label('Online');
        $storage = new Storage('Mine', '/mnt/anime', StorageType::Folder);
        $this->entityManager->persist($studio);
        $this->entityManager->persist($label);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        $record = new V1AnimeRecord(1, 'A', studio: 'Madhouse', labels: ['Online'], storage: new V1StorageRecord('v1 name', '/mnt/anime', 'folder'));

        $this->assertSame([$studio], $this->resolver->resolveStudios($record));
        $this->assertSame([$label], $this->resolver->resolveLabels($record));
        $this->assertSame($storage, $this->resolver->resolveStorage($record));
        $this->assertSame(0, $this->resolver->storagesCreated());
    }

    public function testCreatesEachMissingReferenceOnce(): void
    {
        $record = new V1AnimeRecord(1, 'A', studio: 'Gonzo', labels: ['Online']);

        $this->assertSame($this->resolver->resolveStudios($record), $this->resolver->resolveStudios($record));
        $this->assertSame($this->resolver->resolveLabels($record), $this->resolver->resolveLabels($record));
    }

    public function testStorageRules(): void
    {
        $unknownType = $this->resolver->resolveStorage(new V1AnimeRecord(1, 'A', storage: new V1StorageRecord('S', '/definitely/not/here', 'weird')));
        $this->assertNotNull($unknownType);
        $this->assertSame(StorageType::Folder, $unknownType->getType());
        $this->assertSame(1, $this->resolver->storagesUnavailable());

        $this->assertNull($this->resolver->resolveStorage(new V1AnimeRecord(2, 'B', storage: new V1StorageRecord('No path', null, 'folder'))));
        $this->assertNull($this->resolver->resolveStorage(new V1AnimeRecord(3, 'C', storage: new V1StorageRecord('Relative', 'media/anime', 'folder'))));
        $this->assertSame(['No path', 'Relative'], $this->resolver->storagesSkipped());
        $this->assertSame(1, $this->resolver->storagesCreated());
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
