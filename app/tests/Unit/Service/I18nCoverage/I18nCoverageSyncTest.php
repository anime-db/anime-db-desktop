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
use App\Service\I18nCoverage\I18nCoverageIssueSnapshot;
use App\Service\I18nCoverage\I18nCoverageSync;
use App\Tests\Unit\Service\I18nCoverage\Fake\FakeApplicationTranslationKeysSource;
use App\Tests\Unit\Service\I18nCoverage\Fake\FakeCodeownersSource;
use App\Tests\Unit\Service\I18nCoverage\Fake\FakeI18nCoverageIssueExecutor;
use App\Tests\Unit\Service\I18nCoverage\Fake\FakeI18nCoverageIssueStateSource;
use App\Tests\Unit\Service\I18nCoverage\Fake\FakePluginTranslationKeysSource;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see I18nCoverageSync}, the glue between the three source interfaces,
 * {@see \App\Service\I18nCoverage\I18nCoverageIssueDecider} and
 * {@see \App\Service\I18nCoverage\I18nCoverageIssueExecutor} — everything the pure decider test
 * cannot reach on its own: label bookkeeping, owner-mention wiring, and `--dry-run`.
 */
final class I18nCoverageSyncTest extends TestCase
{
    private const string CODEOWNERS = "/plugins/animedb-language-pack/ @peter-gribanov\n";

    public function testCreatingAnIssueCreatesTheMissingPluginLabel(): void
    {
        $executor = new FakeI18nCoverageIssueExecutor();

        (new I18nCoverageSync())->run(
            'animedb-language-pack',
            new FakeApplicationTranslationKeysSource(['welcome', 'goodbye']),
            new FakePluginTranslationKeysSource(['welcome']),
            new FakeI18nCoverageIssueStateSource(I18nCoverageIssueSnapshot::none(), existingLabels: []),
            new FakeCodeownersSource(self::CODEOWNERS),
            $executor,
            dryRun: false,
        );

        self::assertCount(1, $executor->createdLabels);
        self::assertSame('animedb-language-pack', $executor->createdLabels[0]['name']);
        self::assertCount(1, $executor->createdIssues);
    }

    public function testCreatingAnIssueDoesNotRecreateAnExistingPluginLabel(): void
    {
        $executor = new FakeI18nCoverageIssueExecutor();

        (new I18nCoverageSync())->run(
            'animedb-language-pack',
            new FakeApplicationTranslationKeysSource(['welcome', 'goodbye']),
            new FakePluginTranslationKeysSource(['welcome']),
            new FakeI18nCoverageIssueStateSource(I18nCoverageIssueSnapshot::none(), existingLabels: ['animedb-language-pack']),
            new FakeCodeownersSource(self::CODEOWNERS),
            $executor,
            dryRun: false,
        );

        self::assertSame([], $executor->createdLabels);
        self::assertCount(1, $executor->createdIssues);
    }

    public function testCreatedIssueMentionsTheCodeownersOwner(): void
    {
        $executor = new FakeI18nCoverageIssueExecutor();

        (new I18nCoverageSync())->run(
            'animedb-language-pack',
            new FakeApplicationTranslationKeysSource(['welcome', 'goodbye']),
            new FakePluginTranslationKeysSource(['welcome']),
            new FakeI18nCoverageIssueStateSource(I18nCoverageIssueSnapshot::none(), existingLabels: ['animedb-language-pack']),
            new FakeCodeownersSource(self::CODEOWNERS),
            $executor,
            dryRun: false,
        );

        self::assertStringContainsString('@peter-gribanov', $executor->createdIssues[0]['body']);
        self::assertSame(['i18n-coverage', 'animedb-language-pack'], $executor->createdIssues[0]['labels']);
    }

    public function testCreatedIssueOmitsMentionWhenCodeownersHasNoEntryForThePlugin(): void
    {
        $executor = new FakeI18nCoverageIssueExecutor();

        (new I18nCoverageSync())->run(
            'unlisted-plugin',
            new FakeApplicationTranslationKeysSource(['welcome', 'goodbye']),
            new FakePluginTranslationKeysSource(['welcome']),
            new FakeI18nCoverageIssueStateSource(I18nCoverageIssueSnapshot::none(), existingLabels: ['unlisted-plugin']),
            new FakeCodeownersSource(self::CODEOWNERS),
            $executor,
            dryRun: false,
        );

        self::assertCount(1, $executor->createdIssues);
        self::assertStringNotContainsString('@', $executor->createdIssues[0]['body']);
    }

    public function testDryRunComputesTheDecisionWithoutCallingTheExecutor(): void
    {
        $executor = new FakeI18nCoverageIssueExecutor();

        $decision = (new I18nCoverageSync())->run(
            'animedb-language-pack',
            new FakeApplicationTranslationKeysSource(['welcome', 'goodbye']),
            new FakePluginTranslationKeysSource(['welcome']),
            new FakeI18nCoverageIssueStateSource(I18nCoverageIssueSnapshot::none(), existingLabels: []),
            new FakeCodeownersSource(self::CODEOWNERS),
            $executor,
            dryRun: true,
        );

        self::assertSame(I18nCoverageAction::CREATE, $decision->action);
        self::assertSame(['goodbye'], $decision->missingKeys);
        self::assertSame([], $executor->createdIssues);
        self::assertSame([], $executor->createdLabels);
        self::assertSame([], $executor->rewrittenBodies);
        self::assertSame([], $executor->comments);
        self::assertSame([], $executor->closed);
    }

    public function testUnchangedDeltaAcrossTwoRunsRewritesWithoutCommentingThenChangedDeltaComments(): void
    {
        $codeowners = new FakeCodeownersSource(self::CODEOWNERS);
        $appKeys = new FakeApplicationTranslationKeysSource(['welcome', 'goodbye']);
        $pluginKeys = new FakePluginTranslationKeysSource(['welcome']);

        $firstRun = (new I18nCoverageSync())->run(
            'animedb-language-pack',
            $appKeys,
            $pluginKeys,
            new FakeI18nCoverageIssueStateSource(I18nCoverageIssueSnapshot::none(), existingLabels: ['animedb-language-pack']),
            $codeowners,
            new FakeI18nCoverageIssueExecutor(),
            dryRun: false,
        );
        self::assertSame(I18nCoverageAction::CREATE, $firstRun->action);
        \assert($firstRun->body !== null);

        // Second run: nothing changed on either side — same open issue, same plugin key set.
        $secondExecutor = new FakeI18nCoverageIssueExecutor();
        $secondRun = (new I18nCoverageSync())->run(
            'animedb-language-pack',
            $appKeys,
            $pluginKeys,
            new FakeI18nCoverageIssueStateSource(I18nCoverageIssueSnapshot::open(7, $firstRun->body), existingLabels: ['animedb-language-pack']),
            $codeowners,
            $secondExecutor,
            dryRun: false,
        );

        self::assertSame(I18nCoverageAction::REWRITE_BODY, $secondRun->action);
        self::assertCount(1, $secondExecutor->rewrittenBodies);
        self::assertSame([], $secondExecutor->comments);

        // Third run: the app gained a new key the plugin does not have — the delta changed.
        $thirdExecutor = new FakeI18nCoverageIssueExecutor();
        $thirdRun = (new I18nCoverageSync())->run(
            'animedb-language-pack',
            new FakeApplicationTranslationKeysSource(['welcome', 'goodbye', 'farewell']),
            $pluginKeys,
            new FakeI18nCoverageIssueStateSource(I18nCoverageIssueSnapshot::open(7, $secondRun->body ?? $firstRun->body), existingLabels: ['animedb-language-pack']),
            $codeowners,
            $thirdExecutor,
            dryRun: false,
        );

        self::assertSame(I18nCoverageAction::REWRITE_BODY_AND_COMMENT, $thirdRun->action);
        self::assertCount(1, $thirdExecutor->rewrittenBodies);
        self::assertCount(1, $thirdExecutor->comments);
        self::assertStringContainsString('farewell', $thirdExecutor->comments[0]['comment']);
    }

    public function testEmptyDeltaWithNoOpenIssueDoesNothingAtAll(): void
    {
        $executor = new FakeI18nCoverageIssueExecutor();

        $decision = (new I18nCoverageSync())->run(
            'animedb-language-pack',
            new FakeApplicationTranslationKeysSource(['welcome']),
            new FakePluginTranslationKeysSource(['welcome', 'extra']),
            new FakeI18nCoverageIssueStateSource(I18nCoverageIssueSnapshot::none()),
            new FakeCodeownersSource(self::CODEOWNERS),
            $executor,
            dryRun: false,
        );

        self::assertSame(I18nCoverageAction::NONE, $decision->action);
        self::assertSame([], $executor->createdIssues);
        self::assertSame([], $executor->closed);
    }

    public function testEmptyDeltaWithAnOpenIssueClosesIt(): void
    {
        $executor = new FakeI18nCoverageIssueExecutor();

        $decision = (new I18nCoverageSync())->run(
            'animedb-language-pack',
            new FakeApplicationTranslationKeysSource(['welcome']),
            new FakePluginTranslationKeysSource(['welcome', 'extra']),
            new FakeI18nCoverageIssueStateSource(I18nCoverageIssueSnapshot::open(9, 'stale body')),
            new FakeCodeownersSource(self::CODEOWNERS),
            $executor,
            dryRun: false,
        );

        self::assertSame(I18nCoverageAction::CLOSE, $decision->action);
        self::assertSame([9], $executor->closed);
    }
}
