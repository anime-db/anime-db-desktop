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

namespace App\Service\I18nCoverage;

/**
 * The currently open `i18n-coverage` issue for a plugin, as reported by
 * {@see I18nCoverageIssueStateSource} — or the absence of one. `body` carries the previous run's
 * delta (see {@see I18nCoverageIssueBody::parsePreviousDelta()}), which is how
 * {@see I18nCoverageIssueDecider} tells "delta unchanged" from "delta changed" without any state
 * of its own: the issue body itself is the only persisted snapshot between runs.
 */
final class I18nCoverageIssueSnapshot
{
    private function __construct(
        public readonly bool $exists,
        public readonly ?int $number,
        public readonly string $body,
    ) {
    }

    public static function none(): self
    {
        return new self(false, null, '');
    }

    public static function open(int $number, string $body): self
    {
        return new self(true, $number, $body);
    }
}
