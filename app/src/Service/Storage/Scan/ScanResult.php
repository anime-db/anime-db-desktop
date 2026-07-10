<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service\Storage\Scan;

/**
 * Outcome of ScanStorageService::scan(). A desktop.ini marker conflict aborts the scan with no
 * side effects at all — modelled as a distinct $conflicted state rather than an exception, since
 * it is an expected outcome the caller must branch on, not a failure (see Таск 3 часть 5).
 */
final class ScanResult
{
    /** @param list<ScanResultItem> $items */
    private function __construct(
        public readonly bool $conflicted,
        public readonly array $items,
    ) {
    }

    public static function conflict(): self
    {
        return new self(true, []);
    }

    /** @param list<ScanResultItem> $items */
    public static function items(array $items): self
    {
        return new self(false, $items);
    }
}
