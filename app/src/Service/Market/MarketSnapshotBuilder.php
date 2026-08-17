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

/**
 * Builds a {@see MarketSnapshot} from an already parsed, already signature-verified
 * {@see PluginRegistry} (issue #436, part of the market snapshot epic #435) — the flattening step
 * between the trusted registry and the artifact the storefront eventually renders from.
 *
 * A plugin with no version compatible with `$coreVersion` is still included in the snapshot, with
 * `resolvedVersion`/`sha256` left `null` (see {@see MarketSnapshotPlugin}) — dropping it instead
 * would leave the storefront with no way to show it inactive with a "needs core version X" hint.
 *
 * Fetching, signature/anti-rollback verification and asset download stay entirely upstream of
 * this class ({@see PluginRegistryLoader}, {@see MarketAssetDownloader}) — this builder only
 * transforms already-trusted data.
 */
final class MarketSnapshotBuilder
{
    public function build(PluginRegistry $registry, string $coreVersion): MarketSnapshot
    {
        return new MarketSnapshot(
            $coreVersion,
            $registry->sequence,
            $registry->assetMirrors,
            array_map(
                fn (MarketPlugin $plugin): MarketSnapshotPlugin => $this->buildPlugin($registry, $plugin, $coreVersion),
                $registry->plugins(),
            ),
        );
    }

    private function buildPlugin(PluginRegistry $registry, MarketPlugin $plugin, string $coreVersion): MarketSnapshotPlugin
    {
        $resolvedVersion = $plugin->resolveCompatibleVersion($coreVersion);

        return new MarketSnapshotPlugin(
            (string) $plugin->id,
            $this->manifestToArray($plugin->manifest),
            $resolvedVersion?->version,
            $resolvedVersion !== null ? $registry->findVersionSha256($plugin->id, $resolvedVersion->version) : null,
            $plugin->latestVersion()->version,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestToArray(Manifest $manifest): array
    {
        return [
            'id' => $manifest->id,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'type' => $manifest->type->value,
            'require' => [
                'core' => $manifest->require->core,
                'php' => $manifest->require->php,
                'plugin-contracts' => $manifest->require->pluginContracts,
            ],
            'description' => $manifest->description,
            'author' => $manifest->author,
            'features' => $manifest->features,
            'locales' => $manifest->locales,
            'update_url' => $manifest->updateUrl,
        ];
    }
}
