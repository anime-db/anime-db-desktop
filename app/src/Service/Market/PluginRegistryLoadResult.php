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

/**
 * Outcome of {@see PluginRegistryLoader::load()}. Three shapes are possible:
 *
 * - `$registry` set, `$error` null — a freshly downloaded registry passed every check.
 * - `$registry` set, `$error` set — the fresh download failed a check (bad signature, rollback,
 *   unreachable mirrors); `$registry` is the last known-good one served from cache instead.
 * - `$registry` null, `$error` set — the fresh download failed a check *and* there was no cached
 *   registry to fall back to (e.g. first run). The caller has nothing usable to show.
 *
 * Modeled as a result rather than throwing, because "fresh download failed, fall back to cache,
 * still show the user an error" is the actual required behaviour (issue #292), not an
 * exceptional one — callers that only care about "is there a registry to use" can check
 * `registry` alone, while ones that also need to surface "but it could not be refreshed" read
 * `error` too.
 */
final class PluginRegistryLoadResult
{
    private function __construct(
        public readonly ?PluginRegistry $registry,
        public readonly ?\Throwable $error,
    ) {
    }

    public static function fresh(PluginRegistry $registry): self
    {
        return new self($registry, null);
    }

    public static function servedFromCache(PluginRegistry $registry, \Throwable $error): self
    {
        return new self($registry, $error);
    }

    public static function unavailable(\Throwable $error): self
    {
        return new self(null, $error);
    }

    public function isFresh(): bool
    {
        return $this->registry !== null && $this->error === null;
    }
}
