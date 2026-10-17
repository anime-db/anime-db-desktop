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

namespace App\Tests\Unit\Service\Plugin;

use AnimeDb\PluginContracts\Catalog\AnimeField;
use AnimeDb\PluginContracts\Model\AnimeName;
use AnimeDb\PluginContracts\Model\AnimeType;
use AnimeDb\PluginContracts\Model\Demographic;
use AnimeDb\PluginContracts\Model\GenreCode;
use AnimeDb\PluginContracts\Model\NameRole;
use AnimeDb\PluginContracts\Model\ThemeCode;
use App\Event\Catalog\AnimeSnapshot;
use App\Event\Catalog\FieldChange;
use App\Service\Plugin\AnimeFieldProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnimeFieldProjectorTest extends TestCase
{
    /** @return iterable<string, array{string, mixed, mixed, AnimeField, mixed, mixed}> */
    public static function vocabularyFields(): iterable
    {
        yield 'title' => ['title', 'Old', 'New', AnimeField::Title, 'Old', 'New'];
        yield 'alternative names' => [
            'names',
            [['name' => 'B', 'locale' => 'en', 'role' => 'synonym']],
            [['name' => 'B', 'locale' => 'en', 'role' => 'synonym'], ['name' => 'A', 'locale' => null, 'role' => 'official'], ['name' => 'A', 'locale' => 'en', 'role' => 'official']],
            AnimeField::AlternativeNames,
            [new AnimeName('B', 'en', NameRole::Synonym)],
            [new AnimeName('A', null, NameRole::Official), new AnimeName('A', 'en', NameRole::Official), new AnimeName('B', 'en', NameRole::Synonym)],
        ];
        yield 'type' => ['type', 'tv', 'movie', AnimeField::Type, AnimeType::Tv, AnimeType::Movie];
        yield 'demographic' => ['demographic', null, 'seinen', AnimeField::Demographic, null, Demographic::Seinen];
        yield 'genres' => [
            'genres',
            [],
            [['code' => 'drama'], ['code' => 'action']],
            AnimeField::Genres,
            [],
            [GenreCode::Action, GenreCode::Drama],
        ];
        yield 'themes' => [
            'themes',
            [['code' => 'space']],
            [['code' => 'space'], ['code' => 'mecha']],
            AnimeField::Themes,
            [ThemeCode::Space],
            [ThemeCode::Mecha, ThemeCode::Space],
        ];
        yield 'episodes count' => ['episodesCount', 12, null, AnimeField::EpisodesCount, 12, null];
        yield 'sources' => [
            'sources',
            [['url' => 'https://b.example']],
            [['url' => 'https://b.example'], ['url' => 'https://a.example']],
            AnimeField::Sources,
            ['https://b.example'],
            ['https://a.example', 'https://b.example'],
        ];
        yield 'description' => [
            'descriptions',
            [],
            [['locale' => 'ru', 'description' => 'Текст', 'source' => 'x'], ['locale' => 'en', 'description' => 'Text', 'source' => null]],
            AnimeField::Description,
            [],
            ['en' => 'Text', 'ru' => 'Текст'],
        ];
        yield 'date premiere' => [
            'datePremiere',
            null,
            '2020-01-02T03:04:05+00:00',
            AnimeField::DatePremiere,
            null,
            new \DateTimeImmutable('2020-01-02T00:00:00+00:00'),
        ];
        yield 'date end' => [
            'dateEnd',
            '2020-01-02T00:00:00+00:00',
            '2020-03-04T00:00:00+00:00',
            AnimeField::DateEnd,
            new \DateTimeImmutable('2020-01-02T00:00:00+00:00'),
            new \DateTimeImmutable('2020-03-04T00:00:00+00:00'),
        ];
        yield 'duration' => ['durationMinutes', 24, 25, AnimeField::DurationMinutes, 24, 25];
        yield 'studios' => [
            'studios',
            [['name' => 'B']],
            [['name' => 'B'], ['name' => 'A']],
            AnimeField::Studios,
            ['B'],
            ['A', 'B'],
        ];
        yield 'countries' => ['countries', null, ['jp', 'us'], AnimeField::Countries, [], ['JP', 'US']];
        yield 'cover' => ['cover', null, 'dir/cover.jpg', AnimeField::Cover, null, 'cover.jpg'];
    }

    #[DataProvider('vocabularyFields')]
    public function testEveryVocabularyFieldIsProjectedIntoContractTypes(string $internal, mixed $before, mixed $after, AnimeField $field, mixed $expectedBefore, mixed $expectedAfter): void
    {
        $projected = (new AnimeFieldProjector())->projectChanges([$internal => new FieldChange($before, $after)]);

        self::assertSame([$field->value], array_keys($projected));
        self::assertEquals($expectedBefore, $projected[$field->value]->before);
        self::assertEquals($expectedAfter, $projected[$field->value]->after);
    }

    public function testEveryCaseOfTheVocabularyHasAnInternalField(): void
    {
        $covered = array_map(static fn (array $row): string => $row[3]->value, iterator_to_array(self::vocabularyFields()));

        self::assertEqualsCanonicalizing(array_map(static fn (AnimeField $field): string => $field->value, AnimeField::cases()), array_values($covered));
    }

    /** @return iterable<string, array{string}> */
    public static function fieldsOutsideTheVocabulary(): iterable
    {
        yield 'watch status' => ['watchStatus'];
        yield 'watched episodes' => ['watchedEpisodes'];
        yield 'rating' => ['userRating'];
        yield 'notes' => ['notes'];
        yield 'labels' => ['labels'];
        yield 'storage' => ['storage'];
        yield 'storage path' => ['storagePath'];
        yield 'external ids' => ['externalIds'];
        yield 'gallery' => ['images'];
    }

    #[DataProvider('fieldsOutsideTheVocabulary')]
    public function testFieldsOutsideTheVocabularyNeverReachPlugins(string $internal): void
    {
        self::assertSame([], (new AnimeFieldProjector())->projectChanges([$internal => new FieldChange('a', 'b')]));
    }

    public function testOnlyTheSourceOfADescriptionChangedGivesNothing(): void
    {
        $change = new FieldChange(
            [['locale' => 'en', 'description' => 'Same', 'source' => 'one']],
            [['locale' => 'en', 'description' => 'Same', 'source' => 'two']],
        );

        self::assertSame([], (new AnimeFieldProjector())->projectChanges(['descriptions' => $change]));
    }

    public function testReorderedCountriesGiveNothing(): void
    {
        self::assertSame([], (new AnimeFieldProjector())->projectChanges(['countries' => new FieldChange(['JP', 'US'], ['US', 'JP'])]));
    }

    public function testNumericLookingTitlesAreNotEquatedLoosely(): void
    {
        $projected = (new AnimeFieldProjector())->projectChanges(['title' => new FieldChange('1e3', '1000')]);

        self::assertSame(['title'], array_keys($projected));
    }

    public function testChangeOfAnotherFieldDoesNotHideAVocabularyOne(): void
    {
        $projected = (new AnimeFieldProjector())->projectChanges([
            'notes' => new FieldChange(null, 'n'),
            'title' => new FieldChange('A', 'B'),
        ]);

        self::assertSame(['title'], array_keys($projected));
    }

    public function testSnapshotHasEveryVocabularyKeyAndNothingElse(): void
    {
        $values = (new AnimeFieldProjector())->projectSnapshot(new AnimeSnapshot([
            'title' => 'T',
            'notes' => 'private',
            'storagePath' => 'private',
            'countries' => ['jp'],
            'genres' => [['code' => 'drama']],
        ]));

        self::assertEqualsCanonicalizing(array_map(static fn (AnimeField $field): string => $field->value, AnimeField::cases()), array_keys($values));
        self::assertSame('T', $values['title']);
        self::assertSame(['JP'], $values['countries']);
        self::assertSame([GenreCode::Drama], $values['genres']);
        self::assertNull($values['episodes_count']);
        self::assertSame([], $values['description']);
    }
}
