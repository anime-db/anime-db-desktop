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

namespace App\Tests\Unit\Service\I18nCoverage\Github;

use App\Service\I18nCoverage\AmbiguousIssueStateException;
use App\Service\I18nCoverage\Github\GhIssueStateSource;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real {@see \App\Service\I18nCoverage\Github\GhCommand} against a stub `gh` executable
 * placed first on PATH: it logs its arguments and prints a canned response.
 */
final class GhIssueStateSourceTest extends TestCase
{
    private string $dir;
    private string $originalPath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/gh-stub-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/gh', "#!/bin/sh\necho \"\$@\" >> \"$(dirname \"\$0\")/args.log\"\ncat \"$(dirname \"\$0\")/response.json\"\n");
        chmod($this->dir.'/gh', 0o755);
        $this->originalPath = (string) getenv('PATH');
        putenv('PATH='.$this->dir.':'.$this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testQueriesTheRestIssuesListByBothLabels(): void
    {
        $this->respond('[{"number": 5, "body": "text"}]');

        $snapshot = (new GhIssueStateSource('org/plugins', 'animedb-shikimori', 'plugin-contracts-drift'))->currentState();

        self::assertTrue($snapshot->exists);
        self::assertSame(5, $snapshot->number);
        self::assertSame('text', $snapshot->body);
        self::assertSame('api repos/org/plugins/issues?labels=plugin-contracts-drift,animedb-shikimori&state=open&per_page=100', trim((string) file_get_contents($this->dir.'/args.log')));
    }

    public function testDefaultLabelStaysI18nCoverage(): void
    {
        $this->respond('[]');

        (new GhIssueStateSource('org/plugins', 'animedb-shikimori'))->currentState();

        self::assertStringContainsString('labels=i18n-coverage,animedb-shikimori', (string) file_get_contents($this->dir.'/args.log'));
    }

    public function testPullRequestsAreIgnored(): void
    {
        $this->respond('[{"number": 4, "body": "pr", "pull_request": {}}]');

        self::assertFalse((new GhIssueStateSource('org/plugins', 'a'))->currentState()->exists);
    }

    public function testMoreThanOneOpenIssueIsAmbiguous(): void
    {
        $this->respond('[{"number": 4, "body": "a"}, {"number": 5, "body": "b"}]');

        $this->expectException(AmbiguousIssueStateException::class);

        (new GhIssueStateSource('org/plugins', 'a'))->currentState();
    }

    private function respond(string $json): void
    {
        file_put_contents($this->dir.'/response.json', $json);
    }
}
