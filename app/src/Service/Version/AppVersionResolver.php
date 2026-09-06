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

namespace App\Service\Version;

use Psr\Log\LoggerInterface;

/**
 * The single real source of this app's own version: package.json's "version" field, one
 * directory above the Symfony project root (the repository root in a checkout, or the packaged
 * app's resource directory in a build — see package.json's "asar": false and native/paths.js's
 * `getAppRootDir()`, which locates `app/` the same way relative to that same directory). Electron
 * itself reads this very file via `app.getVersion()`; this is the channel PHP uses to reach the
 * same value without Electron, where CORE_VERSION (native/supervisor/env.js) is never set — a
 * dev run, `bin/console`, or a unit test.
 *
 * Returns `null` — deliberately distinguishable from any real version, rather than a placeholder
 * that would pass as one — when the file is missing, unreadable or does not contain a usable
 * "version" string, and logs why. A caller has to decide explicitly what "unknown" means for it,
 * the same way {@see \App\Service\Plugin\InstalledPluginsRegistry::isCompatible()} already does
 * for the installed plugin-contracts version.
 */
final class AppVersionResolver
{
    public static function resolve(string $projectDir, LoggerInterface $logger): ?string
    {
        $packageJsonPath = \dirname($projectDir).'/package.json';

        $contents = is_file($packageJsonPath) ? file_get_contents($packageJsonPath) : false;
        if ($contents === false) {
            $logger->warning('Unable to read package.json to determine the app version.', [
                'path' => $packageJsonPath,
            ]);

            return null;
        }

        $decoded = json_decode($contents, true);
        $version = \is_array($decoded) ? ($decoded['version'] ?? null) : null;

        if (!\is_string($version) || $version === '') {
            $logger->warning('package.json does not contain a usable "version" field.', [
                'path' => $packageJsonPath,
            ]);

            return null;
        }

        return $version;
    }
}
