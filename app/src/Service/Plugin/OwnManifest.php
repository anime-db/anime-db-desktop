<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;

/**
 * Host implementation of {@see OwnManifestInterface} (contracts v0.9.0, issue #323): a plugin's
 * own id/name/version, baked into its per-plugin service definition at container compile time —
 * see {@see DependencyInjection\Compiler\OwnManifestScopePass}, which constructs one per installed
 * plugin from that plugin's manifest.
 *
 * Unlike {@see SettingsStore}/{@see PluginDataStore}, this never reads anything at runtime: the
 * scalar id/name/version are baked directly into this class's constructor arguments by the
 * compiler pass, because a plugin's manifest only ever changes on install/update, which rebuilds
 * the whole container (see {@see DependencyInjection\Compiler\OwnManifestScopePass} for the full
 * reasoning).
 */
final class OwnManifest implements OwnManifestInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $version,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }
}
