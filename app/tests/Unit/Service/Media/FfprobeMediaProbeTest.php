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

namespace App\Tests\Unit\Service\Media;

use AnimeDb\PluginContracts\Media\MediaFile;
use AnimeDb\PluginContracts\Media\MediaProbeFailedException;
use AnimeDb\PluginContracts\Media\MediaProbeUnavailableException;
use App\Service\Media\FfprobeMediaProbe;
use App\Service\Media\FfprobeOutputMapper;
use App\Service\Media\MediaHandleRegistry;
use PHPUnit\Framework\TestCase;

/** Runs against a stand-in executable script, never against a real ffprobe. */
final class FfprobeMediaProbeTest extends TestCase
{
    private string $dir;
    private string $bin;
    private string $log;
    private MediaHandleRegistry $registry;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ffprobe-probe-test-'.uniqid();
        mkdir($this->dir);
        $this->bin = $this->dir.'/ffprobe.exe';
        $this->log = $this->dir.'/calls.log';
        file_put_contents($this->dir.'/.version', "9.0.2_1\n");
        $this->registry = new MediaHandleRegistry();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', \GLOB_BRACE) ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        rmdir($this->dir);
    }

    private function script(string $body): void
    {
        file_put_contents($this->bin, "#!/bin/sh\necho \"\$@\" >> {$this->log}\n{$body}\n");
        chmod($this->bin, 0o755);
    }

    private function okScript(): void
    {
        $this->script('cat '.escapeshellarg(__DIR__.'/../../../Fixtures/Ffprobe/no_subtitles.json'));
    }

    private function probe(float $timeout = 15.0, ?string $bin = null): FfprobeMediaProbe
    {
        return new FfprobeMediaProbe($this->registry, new FfprobeOutputMapper(), $bin ?? $this->bin, $timeout);
    }

    private function issue(string $name, int $animeId = 1): MediaFile
    {
        $file = new MediaFile($name, $name, 10, new \DateTimeImmutable());
        $this->registry->register($file, $animeId, '/media/'.$animeId.'/'.$name);

        return $file;
    }

    private function calls(): int
    {
        return is_file($this->log) ? \count(file($this->log) ?: []) : 0;
    }

    public function testMissingBinaryIsUnavailable(): void
    {
        $this->expectException(MediaProbeUnavailableException::class);
        $this->probe()->probeAll([$this->issue('a.mkv')]);
    }

    public function testNonExecutableBinaryIsUnavailable(): void
    {
        file_put_contents($this->bin, "#!/bin/sh\n");
        chmod($this->bin, 0o644);

        $this->expectException(MediaProbeUnavailableException::class);
        $this->probe()->probeAll([$this->issue('a.mkv')]);
    }

    public function testEmptyInputGivesEmptyResult(): void
    {
        self::assertSame([], $this->probe()->probeAll([]));
    }

    public function testRunsExactArgumentsOncePerFileSequentially(): void
    {
        $this->okScript();
        $result = $this->probe()->probeAll([$this->issue('a.mkv'), $this->issue('b.mkv')]);

        self::assertSame(['a.mkv', 'b.mkv'], array_keys($result));
        self::assertSame(
            ["-v error -print_format json -show_format -show_streams /media/1/a.mkv\n", "-v error -print_format json -show_format -show_streams /media/1/b.mkv\n"],
            file($this->log),
        );
    }

    public function testTimeoutFails(): void
    {
        $this->script('exec sleep 5');

        $this->expectException(MediaProbeFailedException::class);
        $this->probe(0.3)->probe($this->issue('a.mkv'));
    }

    public function testNonZeroExitFails(): void
    {
        $this->script('exit 1');

        $this->expectException(MediaProbeFailedException::class);
        $this->probe()->probe($this->issue('a.mkv'));
    }

    public function testInvalidOutputFails(): void
    {
        $this->script('echo "not json"');

        $this->expectException(MediaProbeFailedException::class);
        $this->probe()->probe($this->issue('a.mkv'));
    }

    public function testFailedFileIsAbsentWithoutException(): void
    {
        $this->script('for last; do :; done; case "$last" in *bad*) exit 1;; esac; cat '.escapeshellarg(__DIR__.'/../../../Fixtures/Ffprobe/no_subtitles.json'));
        $result = $this->probe()->probeAll([$this->issue('a.mkv'), $this->issue('bad.mkv'), $this->issue('c.mkv')]);

        self::assertSame(['a.mkv', 'c.mkv'], array_keys($result));
        self::assertSame(3, $this->calls());
    }

    public function testAllFailedThrows(): void
    {
        $this->script('exit 1');

        $this->expectException(MediaProbeFailedException::class);
        $this->probe()->probeAll([$this->issue('a.mkv'), $this->issue('b.mkv')]);
    }

    public function testFilesOfDifferentRecordsAreRefusedBeforeAnyProcess(): void
    {
        $this->okScript();

        try {
            $this->probe()->probeAll([$this->issue('01.mkv', 1), $this->issue('01.mkv', 2)]);
            self::fail('Expected MediaProbeFailedException');
        } catch (MediaProbeFailedException) {
            self::assertSame(0, $this->calls());
        }
    }

    public function testFabricatedHandleIsRefusedWithoutStartingProcess(): void
    {
        $this->okScript();
        $fake = new MediaFile('x', '../../../etc/passwd', 1, new \DateTimeImmutable());

        try {
            $this->probe()->probeAll([$this->issue('a.mkv'), $fake]);
            self::fail('Expected MediaProbeFailedException');
        } catch (MediaProbeFailedException) {
            self::assertSame(0, $this->calls());
        }
    }

    public function testIdentityHasVersionAndSha256(): void
    {
        $this->okScript();

        self::assertSame('9.0.2_1+sha256:'.hash_file('sha256', $this->bin), $this->probe()->probeIdentity());
    }

    public function testIdentityChangesWithBinaryContent(): void
    {
        $this->script('echo one');
        $before = $this->probe()->probeIdentity();
        $this->script('echo two');

        self::assertNotSame($before, $this->probe()->probeIdentity());
    }

    public function testIdentityIsMemoizedByMtimeAndSize(): void
    {
        $this->script('echo one');
        $mtime = (int) filemtime($this->bin);
        $probe = $this->probe();
        $first = $probe->probeIdentity();

        // Same size, same mtime, different bytes: only a cached hash can still give the old value.
        file_put_contents($this->bin, str_replace('one', 'two', (string) file_get_contents($this->bin)));
        touch($this->bin, $mtime);
        self::assertSame($first, $probe->probeIdentity());

        // Changed mtime forces a re-hash.
        touch($this->bin, $mtime + 10);
        self::assertNotSame($first, $probe->probeIdentity());
    }
}
