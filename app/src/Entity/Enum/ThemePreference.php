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

namespace App\Entity\Enum;

/**
 * User-facing color scheme preference, chosen in %AppData%/config.json (see AppSettingsProvider)
 * and exposed to app/assets/js/color-mode.js via a data attribute on <html> (see
 * App\Twig\ThemePreferenceExtension). System follows prefers-color-scheme and keeps reacting to
 * it changing at runtime; Light/Dark pin the Bootstrap color mode regardless of the OS setting.
 */
enum ThemePreference: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';
}
