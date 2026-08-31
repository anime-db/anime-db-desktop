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

use AnimeDb\PluginContracts\Manifest\Manifest;
use App\Entity\ValueObject\PluginId;

/**
 * A single entry of {@see InstalledPluginsRegistry}: the plugin's parsed `manifest.json`, the
 * absolute path it is unpacked into, whether the user has it turned on, and whether it is
 * currently able to run at all.
 *
 * `enabled` is a snapshot taken from {@see PluginsConfigStore} at read time, not a second copy
 * persisted in the on-disk index — see {@see InstalledPluginsRegistry} for why. It is purely the
 * user's own choice and carries no opinion on whether the plugin actually works.
 *
 * `compatible` (issue #561) is a second, independent field rather than folded into `enabled`:
 * it is derived fresh on every read from the manifest's `require.core`/`require.plugin-contracts`
 * against the app's own versions (see {@see InstalledPluginsRegistry::readIndex()}), so unlike
 * `enabled` it has no writer at all — nothing in this codebase ever sets it directly, it is pure
 * computed state. Merging the two into one boolean would make an automatic compatibility change
 * indistinguishable from the user's own toggle, and — the concrete failure this is avoiding —
 * would silently overwrite a `false` the user explicitly chose the moment compatibility happened
 * to return, re-enabling a plugin they turned off on purpose. {@see InstalledPluginsRegistry::enabled()}
 * is the intersection of both.
 */
final class InstalledPlugin
{
    public readonly PluginId $id;

    public function __construct(
        public readonly Manifest $manifest,
        public readonly string $installPath,
        public readonly bool $enabled,
        public readonly bool $compatible = true,
    ) {
        $this->id = new PluginId($manifest->id);
    }
}
