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

namespace App\Tests\Unit\Service\PluginContracts\Drift;

use App\Service\I18nCoverage\I18nCoverageIssueSnapshot;
use App\Service\PluginContracts\Drift\DriftAction;
use App\Service\PluginContracts\Drift\DriftDecision;
use App\Service\PluginContracts\Drift\DriftMarker;
use App\Service\PluginContracts\Drift\PluginContractsDriftSync;
use App\Service\PluginContracts\LagReason;
use App\Service\PluginContracts\PluginContractPins;
use App\Tests\Unit\Service\I18nCoverage\Fake\FakeCodeownersSource;
use App\Tests\Unit\Service\I18nCoverage\Fake\FakeI18nCoverageIssueExecutor;
use App\Tests\Unit\Service\I18nCoverage\Fake\FakeI18nCoverageIssueStateSource;
use PHPUnit\Framework\TestCase;

final class PluginContractsDriftSyncTest extends TestCase
{
    private const string OWNERS = "/plugins/animedb-shikimori/ @maintainer\n";

    private FakeI18nCoverageIssueExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new FakeI18nCoverageIssueExecutor();
    }

    public function testNotLaggingWithoutIssueDoesNothing(): void
    {
        $decision = $this->sync($this->accepting(), I18nCoverageIssueSnapshot::none());

        self::assertSame(DriftAction::NONE, $decision->action);
        self::assertSame(0, $this->writes());
    }

    public function testNotLaggingWithOpenIssueClosesIt(): void
    {
        $decision = $this->sync($this->accepting(), I18nCoverageIssueSnapshot::open(5, 'x'));

        self::assertSame(DriftAction::CLOSE, $decision->action);
        self::assertSame([5], $this->executor->closed);
    }

    public function testLaggingWithoutIssueCreatesItWithTitleLabelsBodyAndOwners(): void
    {
        $decision = $this->sync($this->lagging(), I18nCoverageIssueSnapshot::none());

        self::assertSame(DriftAction::CREATE, $decision->action);
        self::assertCount(1, $this->executor->createdIssues);
        $issue = $this->executor->createdIssues[0];
        self::assertSame('Plugin contracts pin is behind the app (animedb-shikimori)', $issue['title']);
        self::assertSame(['plugin-contracts-drift', 'animedb-shikimori'], $issue['labels']);
        self::assertStringContainsString('animedb-shikimori', $issue['body']);
        self::assertStringContainsString('0.9.2', $issue['body']);
        self::assertStringContainsString('`^0.21`', $issue['body']);
        self::assertStringContainsString('`0.22.0`', $issue['body']);
        self::assertStringContainsString('0.1.0', $issue['body']);
        self::assertStringContainsString('cc @maintainer', $issue['body']);
        self::assertStringContainsString('release.yml', $issue['body']);
        self::assertSame([['name' => 'animedb-shikimori', 'description' => '']], $this->executor->createdLabels);
        $marker = DriftMarker::parse($issue['body']);
        self::assertNotNull($marker);
        self::assertSame('0.22.0', $marker->contractsVersion);
        self::assertSame(LagReason::NO_ACCEPTING_VERSION, $marker->reason);
    }

    public function testExistingPluginLabelIsNotRecreated(): void
    {
        $this->sync($this->lagging(), I18nCoverageIssueSnapshot::none(), labels: ['animedb-shikimori']);

        self::assertSame([], $this->executor->createdLabels);
        self::assertCount(1, $this->executor->createdIssues);
    }

    public function testSameVersionAndReasonRewritesBodyWithoutComment(): void
    {
        $decision = $this->sync($this->lagging(), I18nCoverageIssueSnapshot::open(7, $this->markedBody('0.22.0', LagReason::NO_ACCEPTING_VERSION)));

        self::assertSame(DriftAction::REWRITE_BODY, $decision->action);
        self::assertCount(1, $this->executor->rewrittenBodies);
        self::assertSame([], $this->executor->comments);
    }

    public function testOtherVersionRewritesBodyAndComments(): void
    {
        $decision = $this->sync($this->lagging(), I18nCoverageIssueSnapshot::open(7, $this->markedBody('0.21.0', LagReason::NO_ACCEPTING_VERSION)));

        self::assertSame(DriftAction::REWRITE_BODY_AND_COMMENT, $decision->action);
        self::assertSame(7, $this->executor->rewrittenBodies[0]['number']);
        self::assertStringContainsString('`0.21.0` → `0.22.0`', $this->executor->comments[0]['comment']);
    }

    public function testOtherReasonRewritesBodyAndComments(): void
    {
        $decision = $this->sync($this->lagging(), I18nCoverageIssueSnapshot::open(7, $this->markedBody('0.22.0', LagReason::NOT_PARSEABLE)));

        self::assertSame(DriftAction::REWRITE_BODY_AND_COMMENT, $decision->action);
        self::assertCount(1, $this->executor->comments);
    }

    public function testFailedCommentDoesNotConsumeTheNotification(): void
    {
        $this->executor = new class extends FakeI18nCoverageIssueExecutor {
            public bool $failComments = true;

            public function addComment(int $issueNumber, string $comment): void
            {
                if ($this->failComments) {
                    throw new \RuntimeException('gh failed');
                }
                parent::addComment($issueNumber, $comment);
            }
        };
        $issue = I18nCoverageIssueSnapshot::open(7, $this->markedBody('0.21.0', LagReason::NO_ACCEPTING_VERSION));

        try {
            $this->sync($this->lagging(), $issue);
            self::fail('The comment failure must propagate');
        } catch (\RuntimeException) {
        }
        self::assertSame([], $this->executor->rewrittenBodies, 'the marker must not advance before the comment is out');

        $this->executor->failComments = false;
        $decision = $this->sync($this->lagging(), $issue);

        self::assertSame(DriftAction::REWRITE_BODY_AND_COMMENT, $decision->action);
        self::assertCount(1, $this->executor->comments);
    }

    public function testUnreadableMarkerCommentsOnceThenTheNextRunIsQuiet(): void
    {
        $decision = $this->sync($this->lagging(), I18nCoverageIssueSnapshot::open(7, "edited by hand\n<!-- plugin-contracts-drift:state:{broken -->"));

        self::assertSame(DriftAction::REWRITE_BODY_AND_COMMENT, $decision->action);
        self::assertCount(1, $this->executor->comments);

        $rewritten = $this->executor->rewrittenBodies[0]['body'];
        $this->executor = new FakeI18nCoverageIssueExecutor();
        $next = $this->sync($this->lagging(), I18nCoverageIssueSnapshot::open(7, $rewritten));

        self::assertSame(DriftAction::REWRITE_BODY, $next->action);
        self::assertSame([], $this->executor->comments);
    }

    public function testNotParseableBodyTellsThatBumpingThePinIsUseless(): void
    {
        $lagging = new PluginContractPins('animedb-shikimori', false, [], '0.9.2', '^0.22');

        $this->sync($lagging, I18nCoverageIssueSnapshot::none());

        $body = $this->executor->createdIssues[0]['body'];
        self::assertStringContainsString('чей манифест впереди', $body);
        self::assertStringContainsString('бесполезно', $body);
        self::assertStringNotContainsString('release.yml', $body);
    }

    public function testPluginLevelProblemTouchesNothingAndReadsNothing(): void
    {
        $pins = new PluginContractPins('animedb-shikimori', true, [], null, null, 'broken pin');

        $decision = $this->sync($pins, I18nCoverageIssueSnapshot::open(7, 'x'));

        self::assertSame(DriftAction::CANNOT_CHECK, $decision->action);
        self::assertSame('broken pin', $decision->problem);
        self::assertSame(0, $this->writes());
    }

    public function testDryRunWritesNothingButStillDecides(): void
    {
        $decision = $this->sync($this->lagging(), I18nCoverageIssueSnapshot::none(), dryRun: true);

        self::assertSame(DriftAction::CREATE, $decision->action);
        self::assertSame(0, $this->writes());
    }

    /**
     * @param list<string> $labels
     */
    private function sync(PluginContractPins $pins, I18nCoverageIssueSnapshot $issue, bool $dryRun = false, array $labels = []): DriftDecision
    {
        return (new PluginContractsDriftSync())->run(
            $pins,
            '0.22.0',
            '0.1.0',
            new FakeI18nCoverageIssueStateSource($issue, $labels),
            new FakeCodeownersSource(self::OWNERS),
            $this->executor,
            $dryRun,
        );
    }

    private function lagging(): PluginContractPins
    {
        return new PluginContractPins('animedb-shikimori', true, ['^0.21'], '0.9.2', '^0.21');
    }

    private function accepting(): PluginContractPins
    {
        return new PluginContractPins('animedb-shikimori', true, ['^0.22'], '0.9.2', '^0.22');
    }

    private function markedBody(string $version, LagReason $reason): string
    {
        return "text\n".(new DriftMarker($version, $reason))->render();
    }

    private function writes(): int
    {
        return \count($this->executor->createdIssues) + \count($this->executor->rewrittenBodies)
            + \count($this->executor->comments) + \count($this->executor->closed) + \count($this->executor->createdLabels);
    }
}
