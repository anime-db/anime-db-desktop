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

    public function testOrphanedKeysWithEmptyMissingStillClosesTheIssue(): void
    {
        $previousBody = I18nCoverageIssueBody::render('animedb-language-pack', ['goodbye'], []);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye'],
            ['welcome', 'goodbye', 'orphaned_key'],
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

    public function testNonEmptyDeltaWithNoOpenIssueCreatesOneWithOrphanedSection(): void
    {
        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye'],
            ['welcome', 'zzz_old', 'aaa_old', 'aaa_old'],
            I18nCoverageIssueSnapshot::none(),
            [],
        );

        self::assertSame(I18nCoverageAction::CREATE, $decision->action);
        self::assertSame(['goodbye'], $decision->missingKeys);
        self::assertNotNull($decision->body);

        $orphanedSectionStart = strpos($decision->body, 'Ключей больше нет в приложении:');
        self::assertIsInt($orphanedSectionStart);
        $orphanedSection = substr($decision->body, $orphanedSectionStart);

        self::assertStringContainsString('`aaa_old`', $orphanedSection);
        self::assertStringContainsString('`zzz_old`', $orphanedSection);
        self::assertSame(
            1,
            substr_count($orphanedSection, '`aaa_old`'),
            'aaa_old must be listed once despite appearing twice in $pluginKeys',
        );
        self::assertLessThan(
            strpos($orphanedSection, '`zzz_old`'),
            strpos($orphanedSection, '`aaa_old`'),
            'orphaned keys must be sorted',
        );

        self::assertStringNotContainsString('welcome', $orphanedSection);
    }

    public function testChangedDeltaOnAnOpenIssueRewritesBodyWithOrphanedSection(): void
    {
        $previousBody = I18nCoverageIssueBody::render('animedb-language-pack', ['goodbye'], []);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye'],
            ['zzz_old', 'aaa_old'],
            I18nCoverageIssueSnapshot::open(42, $previousBody),
            [],
        );

        self::assertSame(I18nCoverageAction::REWRITE_BODY_AND_COMMENT, $decision->action);
        self::assertSame(['goodbye', 'welcome'], $decision->missingKeys);
        self::assertNotNull($decision->body);

        $orphanedSectionStart = strpos($decision->body, 'Ключей больше нет в приложении:');
        self::assertIsInt($orphanedSectionStart);
        $orphanedSection = substr($decision->body, $orphanedSectionStart);

        self::assertStringContainsString('`aaa_old`', $orphanedSection);
        self::assertStringContainsString('`zzz_old`', $orphanedSection);
        self::assertLessThan(
            strpos($orphanedSection, '`zzz_old`'),
            strpos($orphanedSection, '`aaa_old`'),
            'orphaned keys must be sorted',
        );
    }

    public function testNonEmptyDeltaWithNoOrphanedKeysOmitsTheSection(): void
    {
        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye'],
            [],
            I18nCoverageIssueSnapshot::none(),
            [],
        );

        self::assertSame(I18nCoverageAction::CREATE, $decision->action);
        self::assertNotNull($decision->body);
        self::assertStringNotContainsString('Ключей больше нет в приложении:', $decision->body);
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

    /**
     * @return array{0: list<string>, 1: array<string, string>, 2: list<string>, 3: array<string, array<string, string>>}
     */
    private static function catalogs(bool $divergent): array
    {
        return [
            ['welcome', 'goodbye'],
            ['welcome' => 'Hello %name%', 'goodbye' => 'Bye'],
            ['welcome', 'goodbye'],
            ['de' => ['welcome' => $divergent ? 'Hallo %nme%' : 'Hallo %name%', 'goodbye' => 'Tschuss']],
        ];
    }

    public function testDivergentPlaceholdersWithNoOpenIssueCreateOne(): void
    {
        [$appKeys, $appValues, $pluginKeys, $pluginValues] = self::catalogs(true);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            $appKeys,
            $pluginKeys,
            I18nCoverageIssueSnapshot::none(),
            [],
            $appValues,
            $pluginValues,
        );

        self::assertSame(I18nCoverageAction::CREATE, $decision->action);
        self::assertSame([], $decision->missingKeys);
        self::assertSame(['welcome'], $decision->divergentKeys);
        self::assertNotNull($decision->body);
        self::assertStringContainsString('Расходятся плейсхолдеры с приложением:', $decision->body);
        self::assertStringContainsString('- `welcome` (de): не хватает `%name%`; лишние `%nme%`', $decision->body);
    }

    public function testMatchingPlaceholdersWithNoOpenIssueDoNothing(): void
    {
        [$appKeys, $appValues, $pluginKeys, $pluginValues] = self::catalogs(false);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            $appKeys,
            $pluginKeys,
            I18nCoverageIssueSnapshot::none(),
            [],
            $appValues,
            $pluginValues,
        );

        self::assertSame(I18nCoverageAction::NONE, $decision->action);
    }

    public function testDivergentPlaceholdersKeepAnOpenIssueOpen(): void
    {
        [$appKeys, $appValues, $pluginKeys, $pluginValues] = self::catalogs(true);
        $previousBody = I18nCoverageIssueBody::render('animedb-language-pack', [], [], [], ['welcome' => ['de' => ['missing' => ['name'], 'extra' => ['nme']]]]);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            $appKeys,
            $pluginKeys,
            I18nCoverageIssueSnapshot::open(7, $previousBody),
            [],
            $appValues,
            $pluginValues,
        );

        self::assertSame(I18nCoverageAction::REWRITE_BODY, $decision->action);
    }

    public function testFixedPlaceholdersCloseAnOpenIssue(): void
    {
        [$appKeys, $appValues, $pluginKeys, $pluginValues] = self::catalogs(false);
        $previousBody = I18nCoverageIssueBody::render('animedb-language-pack', [], [], [], ['welcome' => ['de' => ['missing' => ['name'], 'extra' => ['nme']]]]);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            $appKeys,
            $pluginKeys,
            I18nCoverageIssueSnapshot::open(7, $previousBody),
            [],
            $appValues,
            $pluginValues,
        );

        self::assertSame(I18nCoverageAction::CLOSE, $decision->action);
    }

    public function testKeyThatNoLongerDivergesIsReportedInTheCommentWhileMissingKeysRemain(): void
    {
        $previousBody = I18nCoverageIssueBody::render('animedb-language-pack', ['goodbye'], [], [], ['welcome' => ['de' => ['missing' => ['name'], 'extra' => ['nme']]]]);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye'],
            ['welcome'],
            I18nCoverageIssueSnapshot::open(7, $previousBody),
            [],
            ['welcome' => 'Hello %name%'],
            ['de' => ['welcome' => 'Hallo %name%']],
        );

        self::assertSame(I18nCoverageAction::REWRITE_BODY_AND_COMMENT, $decision->action);
        self::assertSame(['goodbye'], $decision->missingKeys);
        self::assertSame([], $decision->divergentKeys);
        self::assertStringContainsString("Больше не расходятся по плейсхолдерам:\n- `welcome`", (string) $decision->comment);
    }

    public function testBothMissingAndDivergentKeysAreReportedAndTrackedOnAnOpenIssue(): void
    {
        $previousBody = I18nCoverageIssueBody::render('animedb-language-pack', ['goodbye'], []);

        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome', 'goodbye'],
            ['welcome'],
            I18nCoverageIssueSnapshot::open(7, $previousBody),
            [],
            ['welcome' => 'Hello %name%'],
            ['de' => ['welcome' => 'Hallo']],
        );

        self::assertSame(I18nCoverageAction::REWRITE_BODY_AND_COMMENT, $decision->action);
        self::assertSame(['goodbye'], $decision->missingKeys);
        self::assertSame(['welcome'], $decision->divergentKeys);
        self::assertStringContainsString("Стали расходиться по плейсхолдерам:\n- `welcome`", (string) $decision->comment);
        self::assertSame(['welcome'], I18nCoverageIssueBody::parsePreviousDivergentDelta((string) $decision->body));
        self::assertSame(['goodbye'], I18nCoverageIssueBody::parsePreviousDelta((string) $decision->body));
    }

    public function testDivergenceInAnyPluginLocaleMakesTheKeyDivergent(): void
    {
        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome'],
            ['welcome'],
            I18nCoverageIssueSnapshot::none(),
            [],
            ['welcome' => 'Hello %name%'],
            ['de' => ['welcome' => 'Hallo %name%'], 'ja' => ['welcome' => 'Konnichiwa']],
        );

        self::assertSame(['welcome'], $decision->divergentKeys);
        self::assertStringContainsString('- `welcome` (ja): не хватает `%name%`; лишние —', (string) $decision->body);
        self::assertStringNotContainsString('(de)', (string) $decision->body);
    }

    public function testPlaceholdersOfOrphanedKeysAreNotCompared(): void
    {
        $decision = (new I18nCoverageIssueDecider())->decide(
            'animedb-language-pack',
            ['welcome'],
            ['welcome', 'old'],
            I18nCoverageIssueSnapshot::none(),
            [],
            ['welcome' => 'Hello', 'old' => 'Old %x%'],
            ['de' => ['welcome' => 'Hallo', 'old' => 'Alt']],
        );

        self::assertSame(I18nCoverageAction::NONE, $decision->action);
    }
}
