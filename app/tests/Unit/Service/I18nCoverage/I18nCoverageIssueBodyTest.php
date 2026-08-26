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

namespace App\Tests\Unit\Service\I18nCoverage;

use App\Service\I18nCoverage\I18nCoverageIssueBody;
use PHPUnit\Framework\TestCase;

final class I18nCoverageIssueBodyTest extends TestCase
{
    public function testParsePreviousDeltaRoundTripsWithRender(): void
    {
        $body = I18nCoverageIssueBody::render('animedb-language-pack', ['goodbye', 'welcome'], []);

        self::assertSame(['goodbye', 'welcome'], I18nCoverageIssueBody::parsePreviousDelta($body));
    }

    public function testParsePreviousDeltaReturnsEmptyForABodyWithNoMarker(): void
    {
        self::assertSame([], I18nCoverageIssueBody::parsePreviousDelta('A hand-written issue body with no marker at all.'));
    }

    public function testParsePreviousDeltaReturnsEmptyForAMalformedMarker(): void
    {
        self::assertSame([], I18nCoverageIssueBody::parsePreviousDelta('<!-- i18n-coverage:delta:{not valid json -->'));
    }

    public function testRenderOmitsTheCcLineWhenThereAreNoOwners(): void
    {
        $body = I18nCoverageIssueBody::render('animedb-language-pack', ['welcome'], []);

        self::assertStringNotContainsString('cc ', $body);
    }
}
