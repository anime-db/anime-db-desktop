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

namespace App\Service\PluginContracts;

/**
 * One registry plugin reduced to what the lag check needs: the `plugin_contracts` pin of every
 * published version (`null` = the version declares none, so it accepts any contracts version),
 * and the latest version for reporting.
 *
 * `$parsed` is false for a plugin the app's registry parser dropped (its manifest is not
 * understood by this app version): the pins are then taken from the raw registry data for
 * reporting only.
 */
final class PluginContractPins
{
    /**
     * @param list<string|null> $pins
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $parsed,
        public readonly array $pins,
        public readonly ?string $latestVersion,
        public readonly ?string $latestPin,
    ) {
    }
}
