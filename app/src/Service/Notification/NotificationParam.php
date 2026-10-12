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

namespace App\Service\Notification;

/**
 * Declaration of one parameter of a notification kind.
 *
 * Evolution rule: a parameter is only ever added, and an added one declares a default here, which
 * the renderer substitutes into records stored before it existed. A code parameter (`$codes` not
 * empty) is translated as `notification.<kind>.<param>.<code>` and declares the closed set of codes;
 * if it is nullable, null is translated as the {@see self::NULL_CODE} code.
 */
final readonly class NotificationParam
{
    /** The code under which a nullable code parameter holding null is translated (`notification.<kind>.<param>.none`). */
    public const string NULL_CODE = 'none';

    /**
     * @param list<string> $codes
     */
    public function __construct(
        public NotificationParamType $type,
        public bool $nullable = false,
        public bool $hasDefault = false,
        public string|int|bool|null $default = null,
        public array $codes = [],
    ) {
        if ($codes !== [] && $type !== NotificationParamType::String) {
            throw new \InvalidArgumentException('A code parameter must be a string.');
        }
        if ($hasDefault && !$this->accepts($default)) {
            throw new \InvalidArgumentException('The default does not match the parameter type.');
        }
    }

    public function accepts(mixed $value): bool
    {
        return $value === null ? $this->nullable : $this->type->accepts($value);
    }
}
