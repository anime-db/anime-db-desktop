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

namespace App\Service\Settings;

/**
 * A single link in the settings sidebar ({@see SettingsNavigationService}). `$id` is the stable
 * key used to match against the active route (a static route name, or `plugin:<pluginId>` for a
 * per-plugin settings page) — never displayed, only compared.
 */
final class SettingsNavItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $url,
        public readonly bool $active,
        public readonly ?int $badge = null,
    ) {
    }
}
