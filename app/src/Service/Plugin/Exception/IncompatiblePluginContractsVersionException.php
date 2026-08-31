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

namespace App\Service\Plugin\Exception;

/**
 * Thrown by {@see \App\Service\Plugin\ZipPluginInstaller::install()} when the unpacked plugin's
 * `manifest.json` declares a `require.plugin-contracts` constraint that the currently installed
 * `anime-db/plugin-contracts` version does not satisfy. A blocking error, kept separate from
 * {@see IncompatiblePluginCoreVersionException}: the two checks compare against different
 * versions (the app's own version vs. the vendored contract library's version) and warrant a
 * different message to the user — this one means the plugin was built against interfaces/DTOs
 * the installed contract library no longer provides in that shape, not that the app itself is too
 * old.
 */
final class IncompatiblePluginContractsVersionException extends \RuntimeException
{
    public function __construct(
        public readonly string $requiredPluginContracts,
        public readonly string $installedPluginContracts,
    ) {
        parent::__construct(\sprintf(
            'Plugin requires plugin-contracts version "%s", but the installed plugin-contracts version is "%s".',
            $requiredPluginContracts,
            $installedPluginContracts,
        ));
    }
}
