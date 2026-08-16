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

/*
 * Standalone worker for ZipPluginInstallerConcurrencyTest: installs the plugin id packaged in
 * $zipPath into $pluginsDir. Run two of these against the same $pluginsDir at once, each
 * packaging a different version of the *same* plugin id — without ZipPluginInstaller::install()
 * running under InstalledPluginsRegistry::synchronized(), both processes could pass the
 * has()/is_dir() collision check before either finishes moving its directory into place, and the
 * loser's rollback (removeDirectory($targetDir)) would then delete the directory the winner had
 * already moved into place (issue #420).
 *
 * Writes exactly one line to $resultPath: "installed:<version>" on success, or
 * "already-installed" if PluginAlreadyInstalledException was thrown (the expected outcome for
 * whichever of the two processes loses the race). Any other exception is left to propagate and
 * crash the process, surfaced via the parent test's getErrorOutput().
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Service\Plugin\Exception\PluginAlreadyInstalledException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheWarmerInterface;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\ZipPluginInstaller;
use App\Service\WsPublisher;
use Psr\Log\NullLogger;

[, $pluginsDir, $coreVersion, $zipPath, $resultPath] = $argv;

$registry = new InstalledPluginsRegistry(
    $pluginsDir,
    new PluginsConfigStore($pluginsDir.'/plugins.json'),
    new NullLogger(),
);

$noopCacheWarmer = new class implements PluginCacheWarmerInterface {
    public function warmUp(): void
    {
    }
};

$noopWsPublisher = new class extends WsPublisher {
    public function __construct()
    {
    }

    public function publish(string $event, mixed $data): void
    {
    }
};

$installer = new ZipPluginInstaller(
    $pluginsDir,
    $coreVersion,
    $registry,
    $noopCacheWarmer,
    $noopWsPublisher,
    logger: new NullLogger(),
);

try {
    $pluginId = $installer->install($zipPath);
    $installed = $registry->get($pluginId);
    file_put_contents($resultPath, 'installed:'.($installed?->manifest->version ?? 'unknown'));
} catch (PluginAlreadyInstalledException) {
    file_put_contents($resultPath, 'already-installed');
}
