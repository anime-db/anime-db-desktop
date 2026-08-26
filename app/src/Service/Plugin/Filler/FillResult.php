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

namespace App\Service\Plugin\Filler;

/**
 * Outcome of {@see FieldFillerService::fill()} (issue #507): a plain bool could not tell "the
 * source had nothing for this anime" apart from "the source had an image, but it could not be
 * downloaded or normalized" - both used to collapse into the same false/error_fill_not_found
 * pair, which pointed a user at the wrong plugin instead of the actual (unrelated) media issue.
 */
enum FillResult
{
    /** The field changed and the change was flushed. */
    case Applied;

    /** The plugin found no match, or resolved data left the field empty. */
    case NotFound;

    /**
     * The plugin returned image data (cover URL or a non-empty images list), but none of it
     * survived download/normalization - see {@see PluginAnimeDataMerger::apply()}.
     */
    case ImageRejected;
}
