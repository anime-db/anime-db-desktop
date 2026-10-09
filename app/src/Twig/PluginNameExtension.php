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

namespace App\Twig;

use App\Service\Plugin\PluginDisplayName;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `plugin_name()` turns a plugin id stored in a payload or a snapshot row into the display name
 * from the plugin's manifest. A plugin that is no longer installed has no manifest to read, and an
 * id that is not a valid plugin id (e.g. a non-plugin participant) has none either, so both fall
 * back to the raw value.
 */
final class PluginNameExtension extends AbstractExtension
{
    public function __construct(
        private readonly PluginDisplayName $displayName,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('plugin_name', $this->name(...)),
        ];
    }

    public function name(string $pluginId): string
    {
        return $this->displayName->name($pluginId);
    }
}
