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

use App\Service\Plugin\InstalledPlugin;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;

/**
 * Answers "is there a compatible version of this installed plugin strictly newer than the one on
 * disk" from the cached {@see MarketSnapshot}. Shared by the market storefront and the installed
 * plugins list, so the "newer" decision lives in one place.
 *
 * Read-only and network-free: a missing snapshot, one built for another core version, a plugin
 * absent from it or one with no compatible version all yield `null` instead of an exception.
 * The snapshot is read on every call (no memoization): the app runs in a long-lived worker and the
 * refresh command may swap the file at any time.
 */
final class MarketUpdateResolver
{
    public function __construct(
        private readonly MarketSnapshotCache $snapshotCache,
        private readonly ?string $coreVersion,
    ) {
    }

    /**
     * @return string|null the resolved compatible version when strictly newer than the installed one
     */
    public function availableUpdate(InstalledPlugin $plugin): ?string
    {
        $snapshot = $this->snapshotCache->load();
        if ($snapshot === null || $snapshot->coreVersion !== $this->coreVersion) {
            return null;
        }

        foreach ($snapshot->plugins as $snapshotPlugin) {
            if ($snapshotPlugin->id !== (string) $plugin->id) {
                continue;
            }

            $resolvedVersion = $snapshotPlugin->resolvedVersion;
            if ($resolvedVersion !== null && $this->isNewerVersion($resolvedVersion, $plugin->manifest->version)) {
                return $resolvedVersion;
            }

            return null;
        }

        return null;
    }

    /**
     * `Comparator::greaterThan()` compares its raw arguments with PHP's `version_compare()`, which
     * does not treat differently-formatted-but-equal versions (e.g. `1.0` and `1.0.0`) as equal —
     * normalizing both through {@see VersionParser::normalize()} first, the same way
     * {@see \Composer\Semver\Semver::satisfies()} normalizes its `$version` argument, fixes that.
     */
    public function isNewerVersion(string $resolvedVersion, string $installedVersion): bool
    {
        $versionParser = new VersionParser();

        return Comparator::greaterThan(
            $versionParser->normalize($resolvedVersion),
            $versionParser->normalize($installedVersion),
        );
    }
}
