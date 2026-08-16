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

namespace App\Service\Plugin;

use App\Entity\ValueObject\PluginId;

/**
 * Removes an installed plugin's directory from `%app.plugins_dir%` and re-syncs
 * {@see InstalledPluginsRegistry} so it no longer appears in `installed-plugins.php`.
 *
 * Used by {@see \App\Command\PluginDeactivateCommand}, the CLI entry point the native supervisor
 * (`native/supervisor/index.js`) shells out to when a live activation restart fails its
 * healthcheck after a plugin install (issue #411): the isolated warm-up already proved the
 * container compiles with the plugin present, but the real FrankenPHP worker can still fail to
 * come up for reasons the warm-up cannot observe (e.g. behavior that only triggers on a real HTTP
 * request). Rolling the plugin back here, rather than leaving it in the index, is what lets the
 * native side restart into a known-good, pre-plugin state instead of looping on a broken one.
 *
 * A no-op (besides the reconcile) when the plugin id is not currently installed — the caller may
 * race with a manual removal or an already-completed rollback, and idempotency here means it does
 * not need to check first.
 *
 * Runs under {@see InstalledPluginsRegistry::synchronized()} (issue #420), the same exclusive lock
 * {@see ZipPluginInstaller::install()}/`update()` already hold for their own whole operation — see
 * that lock's docblock for why: FrankenPHP's worker mode runs requests in parallel on a shared
 * filesystem, so without it a remove could interleave with a concurrent install/update touching
 * the same or a different plugin id.
 */
final class PluginRemover
{
    public function __construct(private readonly InstalledPluginsRegistry $registry)
    {
    }

    public function remove(PluginId $id): void
    {
        $this->registry->synchronized(function () use ($id): void {
            $installed = $this->registry->get($id);
            if ($installed !== null) {
                PluginDirectoryRemover::remove($installed->installPath);
            }

            $this->registry->reconcile();
        });
    }
}
