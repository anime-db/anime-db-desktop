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

namespace App\Service\Media;

use AnimeDb\PluginContracts\Media\MediaFile;
use AnimeDb\PluginContracts\Media\MediaInfo;
use AnimeDb\PluginContracts\Media\MediaProbeFailedException;
use AnimeDb\PluginContracts\Media\MediaProbeInterface;
use AnimeDb\PluginContracts\Media\MediaProbeUnavailableException;
use App\Service\Exception\FfprobeOutputException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * {@see MediaProbeInterface} on top of the ffprobe executable bundled with the application.
 *
 * Only the file at the configured path is ever executed — PATH is never consulted. The process is
 * started from an argument array (no shell), one process per file, strictly one after another.
 *
 * A {@see MediaFile} is accepted only if {@see MediaHandleRegistry} knows it, i.e. it was issued by
 * `listFiles()` in this very process; anything else (a hand-built handle) is refused before any
 * disk access or process start. Refusals arrive as {@see MediaProbeFailedException}, the type a
 * plugin already handles.
 */
final class FfprobeMediaProbe implements MediaProbeInterface
{
    public const TIMEOUT_SECONDS = 15.0;

    /** @var array{int, int, string}|null modification time, size and sha256 of the prober file */
    private ?array $hashed = null;

    public function __construct(
        private readonly MediaHandleRegistry $registry,
        private readonly FfprobeOutputMapper $mapper,
        private readonly string $ffprobeBin,
        private readonly float $timeout = self::TIMEOUT_SECONDS,
    ) {
    }

    public function probe(MediaFile $file): MediaInfo
    {
        return $this->probeAll([$file])[$file->relativePath]
            ?? throw new MediaProbeFailedException('The file could not be probed');
    }

    public function probeAll(array $files): array
    {
        if ($files === []) {
            return [];
        }

        $paths = $this->resolve($files);
        $this->assertAvailable();
        $identity = $this->probeIdentity();

        $result = [];
        foreach ($files as $i => $file) {
            try {
                $result[$file->relativePath] = $this->mapper->map($this->run($paths[$i]), $identity);
            } catch (MediaProbeFailedException|FfprobeOutputException) {
                continue;
            }
        }

        if ($result === []) {
            throw new MediaProbeFailedException('None of the files could be probed');
        }

        return $result;
    }

    public function probeIdentity(): string
    {
        $this->assertAvailable();
        clearstatcache(true, $this->ffprobeBin);
        $mtime = filemtime($this->ffprobeBin);
        $size = filesize($this->ffprobeBin);

        if ($mtime === false || $size === false) {
            throw new MediaProbeUnavailableException('The prober is not available');
        }

        if ($this->hashed === null || $this->hashed[0] !== $mtime || $this->hashed[1] !== $size) {
            $hash = hash_file('sha256', $this->ffprobeBin);

            if ($hash === false) {
                throw new MediaProbeUnavailableException('The prober is not readable');
            }

            $this->hashed = [$mtime, $size, $hash];
        }

        $version = @file_get_contents(\dirname($this->ffprobeBin).'/.version');

        return \sprintf('%s+sha256:%s', $version === false ? 'unknown' : trim($version), $this->hashed[2]);
    }

    /**
     * @param MediaFile[] $files
     *
     * @return list<string> absolute paths, in the order of $files
     */
    private function resolve(array $files): array
    {
        $paths = [];
        $animeId = null;

        foreach ($files as $file) {
            $handle = $this->registry->get($file);

            if ($handle === null) {
                throw new MediaProbeFailedException('The file was not issued by the media library');
            }

            if ($animeId !== null && $animeId !== $handle->animeId) {
                throw new MediaProbeFailedException('All files of one call must belong to one record');
            }

            $animeId = $handle->animeId;
            $paths[] = $handle->absolutePath;
        }

        return $paths;
    }

    private function assertAvailable(): void
    {
        if (!is_file($this->ffprobeBin) || !is_executable($this->ffprobeBin)) {
            throw new MediaProbeUnavailableException('The prober is not available');
        }
    }

    private function run(string $path): string
    {
        $process = new Process(
            [$this->ffprobeBin, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path],
            null,
            null,
            null,
            $this->timeout,
        );

        try {
            $process->run();
        } catch (ProcessException $e) {
            throw new MediaProbeFailedException('The prober did not finish: '.$e->getMessage(), 0, $e);
        }

        if (!$process->isSuccessful()) {
            throw new MediaProbeFailedException('The prober exited with code '.$process->getExitCode());
        }

        return $process->getOutput();
    }
}
