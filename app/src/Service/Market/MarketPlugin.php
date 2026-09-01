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
     * The highest published version whose `core` constraint the given core version satisfies
     * *and* — if that version declares one — whose `plugin-contracts` constraint the given
     * plugin-contracts version satisfies, or `null` if none is compatible (issue #220 §2 /
     * #562: the plugin is then shown inactive with a "needs core version X" hint built from
     * {@see latestVersion()}, or a "no version compatible with the installed plugin-contracts"
     * hint, instead).
     *
     * A version whose `pluginContracts` is `null` (not published for that version — see
     * {@see MarketPluginVersion::$pluginContracts}) is never blocked on this axis, the same
     * "unknown = no constraint" rule already applied to `translationKeyCount`/`locales`. A `null`
     * $pluginContractsVersion (this app build could not determine its own installed
     * plugin-contracts version) fails open the same way: nothing gets blocked on an axis this
     * app cannot itself evaluate, mirroring {@see \App\Service\Plugin\InstalledPluginsRegistry::isCompatible()}'s
     * fail-open convention for the installed-plugin side of the same axis (issue #561).
     */
    public function resolveCompatibleVersion(string $coreVersion, ?string $pluginContractsVersion): ?MarketPluginVersion
    {
        foreach ($this->versions as $version) {
            if (!Semver::satisfies($coreVersion, $version->core)) {
                continue;
            }

            if ($version->pluginContracts !== null && $pluginContractsVersion !== null
                && !Semver::satisfies($pluginContractsVersion, $version->pluginContracts)) {
                continue;
            }

            return $version;
        }

        return null;
    }

    /**
     * Whether any published version's `core` constraint is satisfied by $coreVersion, regardless
     * of the `plugin-contracts` axis — used ({@see MarketSnapshotBuilder}) to tell apart *why*
     * {@see resolveCompatibleVersion()} found nothing: `false` here means the plugin needs a newer
     * core, same as before issue #562; `true` together with a `null` {@see resolveCompatibleVersion()}
     * result means every core-compatible version was blocked by `plugin-contracts` instead, which
     * calls for a different, honest hint — telling the user to update the app would not fix that
     * case.
     */
    public function hasCoreCompatibleVersion(string $coreVersion): bool
    {
        foreach ($this->versions as $version) {
            if (Semver::satisfies($coreVersion, $version->core)) {
                return true;
            }
        }

        return false;
    }
}
