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

namespace App\Service\Market\Exception;

use App\Entity\ValueObject\PluginId;

/**
 * Thrown by {@see \App\Service\Market\MarketAssetDownloader::downloadPluginZip()} when the
 * loaded registry has no `sha256` entry for the requested plugin id/version pair — there is
 * nothing to verify a downloaded archive against, so no download is attempted at all. The
 * caller asked for a version the registry does not (or no longer) know about.
 */
final class UnknownPluginVersionException extends \RuntimeException
{
    public function __construct(
        public readonly PluginId $pluginId,
        public readonly string $version,
    ) {
        parent::__construct(\sprintf(
            'Plugin registry has no entry for "%s" version "%s".',
            $pluginId,
            $version,
        ));
    }
}
