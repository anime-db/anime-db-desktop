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

namespace App\Entity\ValueObject;

use App\Entity\ValueObject\Exception\InvalidPluginIdException;

/**
 * Plugin identifier, e.g. "animedb-shikimori" for official plugins or "<vendor>-<name>"
 * for community ones. Only the naming format (lowercase slug with a vendor and a name
 * segment) is validated here, cheaply and without I/O; whether a plugin with this id
 * actually exists/is installed is the responsibility of the future plugin manager.
 */
final class PluginId
{
    private const FORMAT = '/^[a-z0-9]+(-[a-z0-9]+)+\z/';

    public readonly string $value;

    public function __construct(string $value)
    {
        if (preg_match(self::FORMAT, $value) !== 1) {
            throw new InvalidPluginIdException(\sprintf('Plugin id must be a lowercase "vendor-name" slug, got "%s".', $value));
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
