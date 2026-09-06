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
 * Locates a single existing file inside an installed plugin's `assets/` directory, shared by
 * {@see \App\Controller\PluginAssetController} (serving it) and {@see \App\Twig\PluginAssetExtension}
 * (building its URL) so the containment rule is only ever implemented once.
 *
 * The plugin's own install directory can be a symlink (a common local-development setup), so
 * containment is checked by resolving both the requested file and the `assets/` base directory
 * through {@see realpath()} and comparing the results, rather than comparing the requested path
 * against the base's own unresolved string form — the latter would reject every file for a
 * symlinked plugin directory.
 */
final class PluginAssetResolver
{
    /**
     * Every extension a plugin's static asset may use — deliberately narrow, and deliberately not
     * resolved by sniffing the file's actual content: {@see \App\Controller\PluginAssetController}
     * always takes a served file's `Content-Type` from this map, so a `.svg` whose bytes happen to
     * look like HTML is still served as `image/svg+xml`, never interpreted as markup by the
     * browser. Shared with {@see \App\Twig\PluginAssetExtension} and
     * {@see PluginUiAssetsResolver} so a URL built anywhere in the app only ever names a file this
     * route actually serves.
     *
     * Extension matching is case-sensitive against these lowercase keys — the plugin monorepo's
     * publish gate already requires lowercase extensions under `assets/`
     * (`anime-db/anime-db-plugins#129`), so `assets/Logo.SVG` is simply not a file this route
     * needs to ever have matched, and normalizing case here would only widen what it accepts
     * beyond what a plugin is allowed to publish.
     */
    public const array CONTENT_TYPES = [
        'css' => 'text/css',
        'js' => 'text/javascript',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'woff2' => 'font/woff2',
    ];

    public function __construct(
        private readonly InstalledPluginsRegistry $installedPlugins,
    ) {
    }

    public static function isServableExtension(string $path): bool
    {
        return isset(self::CONTENT_TYPES[pathinfo($path, \PATHINFO_EXTENSION)]);
    }

    /**
     * An incompatible plugin is excluded the same way a disabled one is: neither is ever offered
     * to the user as active (see {@see InstalledPluginsRegistry::enabled()}), so neither should
     * keep serving assets a page can no longer reference.
     */
    public function findEnabledPlugin(PluginId $id): ?InstalledPlugin
    {
        $plugin = $this->installedPlugins->get($id);

        return $plugin !== null && $plugin->enabled && $plugin->compatible ? $plugin : null;
    }

    /**
     * @return string|null the resolved absolute path of $relativePath inside $plugin's `assets/`
     *                     directory, or null if $relativePath is unsafe, escapes that directory, or
     *                     does not name an existing file
     */
    public function resolveContainedFile(InstalledPlugin $plugin, string $relativePath): ?string
    {
        if (!self::isPathSafe($relativePath)) {
            return null;
        }

        $baseRealPath = realpath($plugin->installPath.\DIRECTORY_SEPARATOR.'assets');
        if ($baseRealPath === false) {
            return null;
        }

        $targetRealPath = realpath($plugin->installPath.\DIRECTORY_SEPARATOR.$relativePath);
        if ($targetRealPath === false || !is_file($targetRealPath)) {
            return null;
        }

        return str_starts_with($targetRealPath, $baseRealPath.\DIRECTORY_SEPARATOR) ? $targetRealPath : null;
    }

    /**
     * Rejects a path before it ever reaches the filesystem: empty, absolute (leading "/" or a
     * Windows drive letter like "C:"), containing a NUL byte or a "\" (Windows directory
     * separator, meaningless as an incoming URL path segment), or containing a ".." segment.
     * {@see self::resolveContainedFile()}'s realpath()-based containment check would catch a
     * traversal attempt too, but rejecting the shape up front keeps that check focused on the
     * symlink case it exists for.
     */
    private static function isPathSafe(string $relativePath): bool
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
            return false;
        }

        if (str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:/', $relativePath) === 1) {
            return false;
        }

        return !\in_array('..', explode('/', $relativePath), true);
    }
}
