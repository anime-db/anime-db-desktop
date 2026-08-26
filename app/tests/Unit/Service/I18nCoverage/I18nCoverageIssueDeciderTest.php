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

use App\Service\I18nCoverage\I18nCoverageAction;
use App\Service\I18nCoverage\I18nCoverageIssueBody;
use App\Service\I18nCoverage\I18nCoverageIssueDecider;
use App\Service\I18nCoverage\I18nCoverageIssueSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Covers the five-row state table from issue #515, entirely on plain arrays — no fake in sight,
 * since {@see I18nCoverageIssueDecider} takes already-resolved values, not source interfaces.
 */
final class I18nCoverageIssueDeciderTest extends TestCase
{
    public function testEmptyDeltaAndNoOpenIssueDoesNothing(): void
    {
        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye'],
            ['welcome', 'goodbye', 'extra'],
            I18nCoverageIssueSnapshot::none(),
            [],
        );

        self::assertSame(I18nCoverageAction::NONE, $decision->action);
        self::assertNull($decision->body);
        self::assertNull($decision->comment);
    }

    public function testEmptyDeltaWithAnOpenIssueClosesIt(): void
    {
        $previousBody = I18nCoverageIssueBody::render('animedb-language-pack', ['goodbye'], []);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye'],
            ['welcome', 'goodbye'],
            I18nCoverageIssueSnapshot::open(42, $previousBody),
            [],
        );

        self::assertSame(I18nCoverageAction::CLOSE, $decision->action);
        self::assertNull($decision->body);
    }

    public function testNonEmptyDeltaWithNoOpenIssueCreatesOne(): void
    {
        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye'],
            ['welcome'],
            I18nCoverageIssueSnapshot::none(),
            ['@peter-gribanov'],
        );

        self::assertSame(I18nCoverageAction::CREATE, $decision->action);
        self::assertSame(['goodbye'], $decision->missingKeys);
        self::assertNotNull($decision->body);
        self::assertStringContainsString('goodbye', $decision->body);
        self::assertStringContainsString('@peter-gribanov', $decision->body);
        self::assertNull($decision->comment);
    }

    public function testUnchangedDeltaOnAnOpenIssueRewritesBodyWithoutCommenting(): void
    {
        $previousBody = I18nCoverageIssueBody::render('animedb-language-pack', ['goodbye'], []);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye'],
            ['welcome'],
            I18nCoverageIssueSnapshot::open(42, $previousBody),
            [],
        );

        self::assertSame(I18nCoverageAction::REWRITE_BODY, $decision->action);
        self::assertSame(['goodbye'], $decision->missingKeys);
        self::assertNull($decision->comment);
    }

    public function testChangedDeltaOnAnOpenIssueRewritesBodyAndComments(): void
    {
        $previousBody = I18nCoverageIssueBody::render('animedb-language-pack', ['goodbye'], []);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye', 'farewell'],
            ['welcome'],
            I18nCoverageIssueSnapshot::open(42, $previousBody),
            [],
        );

        self::assertSame(I18nCoverageAction::REWRITE_BODY_AND_COMMENT, $decision->action);
        self::assertSame(['farewell', 'goodbye'], $decision->missingKeys);
        self::assertNotNull($decision->comment);
        self::assertStringContainsString('farewell', $decision->comment);
    }
}
