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

use App\Entity\ValueObject\PluginId;
use App\Service\Market\Exception\IncompatibleCoreSnapshotException;
use App\Service\Market\Exception\IncompatiblePluginContractsSnapshotException;
use App\Service\Market\Exception\MarketSnapshotUnavailableException;
use App\Service\Market\Exception\PluginAssetDownloadException;
use App\Service\Market\Exception\PluginNotInSnapshotException;
use App\Service\Market\Exception\UnknownPluginVersionException;
use App\Service\Plugin\Exception\IncompatiblePluginCoreVersionException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\Exception\PluginInstallException;
use App\Service\Plugin\Exception\PluginNotInstalledException;
use App\Service\Plugin\ZipPluginInstaller;

/**
 * Installs or updates a plugin from the cached {@see MarketSnapshot}: snapshot check → plugin
 * lookup → resolved version → sha256-verified download ({@see MarketAssetDownloader}) →
 * {@see ZipPluginInstaller} with `trusted: true`, removing the temporary archive afterwards.
 *
 * Every outcome the caller may want to tell apart is a distinct exception, none of them wrapped:
 * {@see MarketSnapshotUnavailableException}, {@see PluginNotInSnapshotException},
 * {@see IncompatiblePluginContractsSnapshotException}, {@see IncompatibleCoreSnapshotException},
 * {@see UnknownPluginVersionException} / {@see PluginAssetDownloadException} from the download, and
 * whatever {@see ZipPluginInstaller::install()} / {@see ZipPluginInstaller::update()} throw.
 */
final class MarketPluginInstaller
{
    public function __construct(
        private readonly MarketSnapshotCache $snapshotCache,
        private readonly MarketAssetDownloader $assetDownloader,
        private readonly ZipPluginInstaller $installer,
        private readonly ?string $coreVersion,
    ) {
    }

    /**
     * @param bool $publishWorkersReload whether {@see ZipPluginInstaller::WORKERS_RELOAD_EVENT} is
     *                                   published after success; pass `false` when the caller
     *                                   activates the plugin itself
     *
     * @throws MarketSnapshotUnavailableException
     * @throws PluginNotInSnapshotException
     * @throws IncompatiblePluginContractsSnapshotException
     * @throws IncompatibleCoreSnapshotException
     * @throws UnknownPluginVersionException
     * @throws PluginAssetDownloadException
     * @throws IncompatiblePluginCoreVersionException
     * @throws PluginAlreadyInstalledException
     * @throws PluginNotInstalledException
     * @throws InvalidInstalledPluginException
     * @throws PluginInstallException                       any other installer failure
     */
    public function installOrUpdate(PluginId $id, bool $update = false, bool $publishWorkersReload = true): PluginId
    {
        $snapshot = $this->snapshotCache->load();
        if ($snapshot === null || !$snapshot->isBuiltFor($this->coreVersion)) {
            throw new MarketSnapshotUnavailableException();
        }

        $plugin = $this->findPlugin($snapshot, $id);
        if ($plugin === null) {
            throw new PluginNotInSnapshotException($id);
        }

        if ($plugin->resolvedVersion === null && $plugin->incompatiblePluginContracts) {
            throw new IncompatiblePluginContractsSnapshotException($id);
        }

        if ($plugin->resolvedVersion === null) {
            throw new IncompatibleCoreSnapshotException($id, $plugin->latestVersionCore);
        }

        $zipPath = $this->assetDownloader->downloadPluginZip($plugin->sha256, $snapshot->assetMirrors, $id, $plugin->resolvedVersion);

        try {
            if ($update) {
                return $this->installer->update($zipPath, trusted: true, publishWorkersReload: $publishWorkersReload);
            }

            return $this->installer->install($zipPath, trusted: true, publishWorkersReload: $publishWorkersReload);
        } finally {
            @unlink($zipPath);
        }
    }

    private function findPlugin(MarketSnapshot $snapshot, PluginId $id): ?MarketSnapshotPlugin
    {
        foreach ($snapshot->plugins as $plugin) {
            if ($plugin->id === (string) $id) {
                return $plugin;
            }
        }

        return null;
    }
}
