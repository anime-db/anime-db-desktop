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

namespace App\Tests\Unit\Service;

use App\Entity\Enum\AnimeType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Service\AnimeListRequestParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class AnimeListRequestParserTest extends TestCase
{
    private AnimeListRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AnimeListRequestParser();
    }

    public function testParseFilterDefaultsWatchStatusesToEmptyWhenMissing(): void
    {
        $filter = $this->parser->parseFilter(new Request());

        $this->assertSame([], $filter->watchStatuses);
    }

    public function testParseFilterAcceptsASingleWatchStatus(): void
    {
        $filter = $this->parser->parseFilter(new Request(['watch_status' => 'watching']));

        $this->assertSame([WatchStatus::Watching], $filter->watchStatuses);
    }

    public function testParseFilterAcceptsAListOfWatchStatuses(): void
    {
        $filter = $this->parser->parseFilter(new Request([
            'watch_status' => [WatchStatus::Watching->value, WatchStatus::Plan->value],
        ]));

        $this->assertSame([WatchStatus::Watching, WatchStatus::Plan], $filter->watchStatuses);
    }

    public function testParseFilterRejectsUnknownEnumValue(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->parser->parseFilter(new Request(['watch_status' => 'not-a-real-status']));
    }

    public function testParseFilterAcceptsAListOfTypes(): void
    {
        $filter = $this->parser->parseFilter(new Request([
            'type' => [AnimeType::Movie->value, AnimeType::Tv->value],
        ]));

        $this->assertSame([AnimeType::Movie, AnimeType::Tv], $filter->types);
    }

    public function testParseFilterRejectsUnknownType(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->parser->parseFilter(new Request(['type' => 'not-a-real-type']));
    }

    public function testParseFilterAcceptsAListOfThemes(): void
    {
        $filter = $this->parser->parseFilter(new Request([
            'themes' => [ThemeCode::Mecha->value, ThemeCode::Isekai->value],
        ]));

        $this->assertSame([ThemeCode::Mecha, ThemeCode::Isekai], $filter->themes);
    }

    public function testParseFilterRejectsUnknownTheme(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->parser->parseFilter(new Request(['themes' => ['not-a-real-theme']]));
    }

    public function testParseFilterBuildsFilterFromAllSupportedParams(): void
    {
        $filter = $this->parser->parseFilter(new Request([
            'watch_status' => [WatchStatus::Watching->value],
            'type' => [AnimeType::Movie->value],
            'countries' => 'JP',
            'name' => 'Trigun',
            'genres' => [GenreCode::Action->value, GenreCode::Comedy->value],
            'studios' => ['1', '2'],
            'labels' => ['3'],
            'themes' => [ThemeCode::Mecha->value],
            'user_rating_from' => '3',
            'user_rating_to' => '5',
            'date_premiere_from' => '2020-01-01',
            'date_premiere_to' => '2021-01-01',
        ]));

        $this->assertSame([WatchStatus::Watching], $filter->watchStatuses);
        $this->assertSame([AnimeType::Movie], $filter->types);
        $this->assertSame('JP', $filter->country);
        $this->assertSame('Trigun', $filter->name);
        $this->assertSame([GenreCode::Action, GenreCode::Comedy], $filter->genres);
        $this->assertSame([1, 2], $filter->studioIds);
        $this->assertSame([3], $filter->labelIds);
        $this->assertSame([ThemeCode::Mecha], $filter->themes);
        $this->assertSame(3, $filter->userRatingFrom);
        $this->assertSame(5, $filter->userRatingTo);
        $this->assertEquals(new \DateTimeImmutable('2020-01-01'), $filter->datePremiereFrom);
        $this->assertEquals(new \DateTimeImmutable('2021-01-01'), $filter->datePremiereTo);
    }

    public function testParseFilterAcceptsUserRatingNoneFlag(): void
    {
        $filter = $this->parser->parseFilter(new Request(['user_rating_none' => '1']));

        $this->assertTrue($filter->userRatingIsNull);
    }

    public function testParseFilterAcceptsDatePremiereNoneFlag(): void
    {
        $filter = $this->parser->parseFilter(new Request(['date_premiere_none' => '1']));

        $this->assertTrue($filter->datePremiereIsNull);
    }

    public function testParseFilterRejectsMalformedDate(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->parser->parseFilter(new Request([
            'watch_status' => 'watching',
            'date_premiere_from' => 'not-a-date',
        ]));
    }

    public function testParsePaginationDefaultsWhenMissing(): void
    {
        [$limit, $offset] = $this->parser->parsePagination(new Request());

        $this->assertSame(20, $limit);
        $this->assertSame(0, $offset);
    }

    public function testParsePaginationClampsLimitAndOffsetToValidRange(): void
    {
        [$limit, $offset] = $this->parser->parsePagination(new Request(['limit' => '0', 'offset' => '-5']));
        $this->assertSame(1, $limit);
        $this->assertSame(0, $offset);

        [$limit] = $this->parser->parsePagination(new Request(['limit' => '1000']));
        $this->assertSame(100, $limit);
    }

    public function testParseOptionalStringReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->parser->parseOptionalString(new Request(), 'sort'));
    }

    public function testParseOptionalStringReturnsRawValueWhenPresent(): void
    {
        $this->assertSame('name', $this->parser->parseOptionalString(new Request(['sort' => 'name']), 'sort'));
    }

    public function testRejectsListValueForAScalarParam(): void
    {
        // Request::query (InputBag) rejects a non-scalar value before assertScalarParam() runs.
        $this->expectException(BadRequestException::class);

        $this->parser->parseFilter(new Request(['countries' => ['JP', 'US']]));
    }
}
