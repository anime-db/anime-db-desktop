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
 * Derives a plugin's PHP namespace from its {@see PluginId}: "vendor-name" becomes namespace
 * `AnimeDb\Plugins\VendorName\`. `manifest.json` intentionally carries neither a namespace nor a
 * bundle class name, so both are derived deterministically from the plugin id wherever needed.
 *
 * The one place this convention is defined, shared by {@see PluginLoader} (autoload
 * registration, bundle class resolution) and
 * {@see DependencyInjection\Compiler\TagPluginServicesPass} (matching a
 * service's class to the plugin that owns it) — keeping it here means the two can never drift
 * apart.
 */
final class PluginNamespace
{
    public static function studlyId(PluginId|string $id): string
    {
        return str_replace('-', '', ucwords((string) $id, '-'));
    }

    public static function prefix(PluginId|string $id): string
    {
        return 'AnimeDb\\Plugins\\'.self::studlyId($id).'\\';
    }
}
