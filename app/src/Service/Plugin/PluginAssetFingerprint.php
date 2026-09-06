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

/**
 * A short content hash of a plugin asset file, used as the `{fingerprint}` path segment of the
 * `plugin_asset` route (see {@see \App\Controller\PluginAssetController}). It is derived from the
 * file's own bytes rather than the plugin's version so that reinstalling the same version with
 * changed files (a private plugin author's normal workflow, issue #251) changes the URL instead of
 * serving stale content under a `Cache-Control: immutable` response for a year.
 *
 * Both the controller (verifying the fingerprint in an incoming URL) and
 * {@see \App\Twig\PluginAssetExtension} (building that URL) must compute it identically, hence the
 * single shared implementation here.
 */
final class PluginAssetFingerprint
{
    private function __construct()
    {
    }

    public static function forFile(string $absolutePath): string
    {
        $hash = hash_file('xxh128', $absolutePath);
        if ($hash === false) {
            throw new \RuntimeException(\sprintf('Unable to hash "%s".', $absolutePath));
        }

        return substr($hash, 0, 16);
    }
}
