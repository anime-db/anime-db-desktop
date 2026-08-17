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

namespace App\Service\Market;

use App\Service\Market\Exception\InvalidMarketSnapshotContentException;

/**
 * Persists the last built {@see MarketSnapshot} at `%app.market_snapshot_cache_path%`, so a
 * refresh command (out of scope for this class, issue #436) has something to atomically swap into
 * place and a future controller has something to read without rebuilding the snapshot per request.
 *
 * Single-writer file, same rationale as {@see PluginRegistryCache}: nothing concurrent writes this
 * cache today, so a temp-file + `rename()` is enough to avoid a reader ever observing a partially
 * written file — no `flock()` needed. Unlike {@see PluginRegistryCache}'s fixed `.tmp` suffix,
 * the temp file name here carries a random suffix, so two writers racing (a future concern, not a
 * current one) would not stomp on the same temp path while each is still writing it.
 */
final class MarketSnapshotCache
{
    public function __construct(private readonly string $snapshotCachePath)
    {
    }

    public function load(): ?MarketSnapshot
    {
        if (!is_file($this->snapshotCachePath)) {
            return null;
        }

        $contents = file_get_contents($this->snapshotCachePath);
        if ($contents === false || $contents === '') {
            return null;
        }

        try {
            return MarketSnapshot::fromJson($contents);
        } catch (InvalidMarketSnapshotContentException) {
            return null;
        }
    }

    public function store(MarketSnapshot $snapshot): void
    {
        $directory = \dirname($this->snapshotCachePath);
        if (!is_dir($directory)) {
            @mkdir($directory, recursive: true);
        }

        $tmpPath = $this->snapshotCachePath.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (@file_put_contents($tmpPath, $snapshot->toJson()) === false) {
            throw new \RuntimeException(\sprintf('Unable to write "%s".', $tmpPath));
        }

        if (!@rename($tmpPath, $this->snapshotCachePath)) {
            throw new \RuntimeException(\sprintf('Unable to move "%s" to "%s".', $tmpPath, $this->snapshotCachePath));
        }
    }
}
