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

use AnimeDb\PluginContracts\Manifest\Manifest;
use App\Entity\ValueObject\PluginId;
use Composer\Semver\Semver;

/**
 * A single plugin's storefront entry from a trusted `plugins-registry.json`
 * ({@see PluginRegistry::plugins()}): the full manifest of its latest published version, used
 * for display (name/description/type/version, issue #220), plus its `versions` list, used to
 * resolve which published version is actually installable against the current
 * `%app.core_version%`.
 *
 * `$versions` is sorted by version number, descending — {@see resolveCompatibleVersion()} relies
 * on that order to stop at the first (i.e. highest) compatible entry.
 */
final class MarketPlugin
{
    /**
     * @param list<MarketPluginVersion> $versions sorted by version, descending
     */
    public function __construct(
        public readonly PluginId $id,
        public readonly Manifest $manifest,
        public readonly array $versions,
    ) {
    }

    /**
     * The highest published version, regardless of core-compatibility — used to show a "core
     * version X required" hint when {@see resolveCompatibleVersion()} finds nothing installable.
     */
    public function latestVersion(): MarketPluginVersion
    {
        return $this->versions[0];
    }

    /**
     * The highest published version whose `core` constraint the given core version satisfies, or
     * `null` if none is compatible (issue #220 §2: the plugin is then shown inactive with a
     * "needs core version X" hint built from {@see latestVersion()} instead).
     */
    public function resolveCompatibleVersion(string $coreVersion): ?MarketPluginVersion
    {
        foreach ($this->versions as $version) {
            if (Semver::satisfies($coreVersion, $version->core)) {
                return $version;
            }
        }

        return null;
    }
}
