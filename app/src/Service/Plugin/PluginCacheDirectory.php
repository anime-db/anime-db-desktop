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

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;
use App\Entity\ValueObject\PluginId;

/**
 * One plugin's own directory under the shared `plugin-cache/` root, bound to that plugin's
 * {@see PluginId} — see {@see DependencyInjection\Compiler\PluginCacheDirectoryScopePass}, which
 * constructs one instance per installed plugin and is the only place a plugin ever obtains one.
 *
 * The directory is created on the first {@see self::path()} call, not at construction, so a plugin
 * that never touches it leaves nothing on disk. The host never empties it while the plugin is
 * installed (see {@see PluginCacheDirectories} for removal).
 */
final class PluginCacheDirectory implements PluginCacheDirectoryInterface
{
    public function __construct(
        private readonly PluginId $pluginId,
        private readonly string $rootDir,
    ) {
    }

    public function path(): string
    {
        $path = rtrim($this->rootDir, '/\\').'/'.$this->pluginId;

        // A concurrent process may create the directory between the check and mkdir(), so the
        // second is_dir() decides, not mkdir()'s return value.
        if (!is_dir($path) && !@mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new \RuntimeException(\sprintf('Unable to create the cache directory "%s".', $path));
        }

        if (!is_writable($path)) {
            throw new \RuntimeException(\sprintf('The cache directory "%s" is not writable.', $path));
        }

        return $path;
    }
}
