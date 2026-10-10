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
use App\Service\Plugin\Exception\PluginDirectoryRemovalException;
use Psr\Log\LoggerInterface;

/**
 * Host-side lifecycle of the `plugin-cache/` root that {@see PluginCacheDirectory} hands out
 * per-plugin directories from. Failures here are only logged: the content is restorable, and
 * keeping a plugin installed (or blocking its removal) over a leftover cache is the worse outcome.
 */
final class PluginCacheDirectories
{
    public function __construct(
        private readonly string $pluginCacheDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function remove(PluginId $id): void
    {
        $this->removeDirectory(rtrim($this->pluginCacheDir, '/\\').'/'.$id);
    }

    /**
     * Deletes every directory under the root whose name is not the id of an installed plugin.
     *
     * @param list<PluginId> $installed
     */
    public function removeOrphans(array $installed): void
    {
        $entries = @scandir($this->pluginCacheDir);
        if ($entries === false) {
            return;
        }

        $keep = array_map(static fn (PluginId $id): string => (string) $id, $installed);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || \in_array($entry, $keep, true)) {
                continue;
            }

            $this->removeDirectory(rtrim($this->pluginCacheDir, '/\\').'/'.$entry);
        }
    }

    private function removeDirectory(string $dir): void
    {
        try {
            PluginDirectoryRemover::remove($dir);
        } catch (PluginDirectoryRemovalException $exception) {
            $this->logger->warning('Unable to remove a plugin cache directory.', [
                'directory' => $dir,
                'exception' => $exception,
            ]);
        }
    }
}
