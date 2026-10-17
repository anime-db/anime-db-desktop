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
 * Thrown by {@see \App\Service\Market\MarketPluginInstaller} when the snapshot resolved no
 * version of the plugin because none is compatible with the running core version.
 */
final class IncompatibleCoreSnapshotException extends \RuntimeException
{
    public function __construct(
        public readonly PluginId $pluginId,
        public readonly string $requiredCore,
    ) {
        parent::__construct(\sprintf('No version of plugin "%s" is compatible with the running core; the latest requires "%s".', $pluginId, $requiredCore));
    }
}
