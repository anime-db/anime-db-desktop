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

use App\Service\Market\Exception\InvalidPluginRegistryContentException;

/**
 * Persists the last successfully verified `plugins-registry.json` at
 * `%app.market_registry_cache_path%`, so {@see PluginRegistryLoader} has something to serve
 * when a fresh download fails, is unsigned/mis-signed, or is rejected as a rollback — and so the
 * last-seen `sequence` survives an app restart (anti-rollback must hold across runs, not just
 * within one process's lifetime).
 *
 * Stores the exact registry bytes as downloaded, not a re-encoded copy: re-serializing through
 * `json_encode()` here would let a future field this class does not know about silently vanish
 * from the cached copy.
 *
 * Single-writer file: unlike {@see \App\Service\Plugin\PluginsConfigStore}, nothing concurrent
 * writes the registry cache today, so a plain temp-file + `rename()` is enough to avoid a reader
 * ever observing a partially written file — no `flock()` needed.
 */
final class PluginRegistryCache
{
    public function __construct(private readonly string $cachePath)
    {
    }

    public function getCachedRegistry(): ?PluginRegistry
    {
        if (!is_file($this->cachePath)) {
            return null;
        }

        $contents = file_get_contents($this->cachePath);
        if ($contents === false || $contents === '') {
            return null;
        }

        try {
            return PluginRegistry::fromJson($contents);
        } catch (InvalidPluginRegistryContentException) {
            return null;
        }
    }

    public function getLastSequence(): ?int
    {
        return $this->getCachedRegistry()?->sequence;
    }

    public function store(string $registryJson): void
    {
        $directory = \dirname($this->cachePath);
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $tmpPath = $this->cachePath.'.tmp';
        if (file_put_contents($tmpPath, $registryJson) === false) {
            throw new \RuntimeException(\sprintf('Unable to write "%s".', $tmpPath));
        }

        rename($tmpPath, $this->cachePath);
    }
}
