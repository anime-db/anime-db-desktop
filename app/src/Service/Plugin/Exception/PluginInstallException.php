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

namespace App\Service\Plugin\Exception;

/**
 * Thrown by {@see \App\Service\Plugin\ZipPluginInstaller::install()} for failures outside of
 * manifest validation itself: the archive cannot be opened/extracted, or the unpacked directory
 * cannot be moved into `%app.plugins_dir%`. Deliberately not used for an invalid or missing
 * manifest.json (that stays as {@see InvalidInstalledPluginException}, re-thrown from
 * {@see \AnimeDb\PluginContracts\Manifest\ManifestParser::parse()}'s own exceptions) or for a
 * plugin id collision ({@see PluginAlreadyInstalledException}) — those are more specific and a
 * caller may want to react to them differently, e.g. show a "plugin already installed" message
 * instead of a generic install failure.
 */
final class PluginInstallException extends \RuntimeException
{
}
