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
 * Outcome of {@see PluginAnimeDataMerger::apply()}: $unapplied and $dateRangeRejected are kept
 * apart because callers (FieldFillerService::fill(), BulkFillerService::fillExistingFromPlugin())
 * turn them into two different {@see FillResult} cases - a rejected date_premiere/date_end pair
 * is a conflict with data already on the record, not a media download failure, and reporting it
 * as {@see FillResult::ImageRejected} (or, worse, letting it fall through to
 * {@see FillResult::Applied} because it was never recorded anywhere) would misinform the user
 * either way.
 */
final class MergeResult
{
    /**
     * @param list<string> $unapplied         source data present but could not be applied - today only
     *                                        'cover'/'images' can end up here, see apply()'s own docblock
     * @param bool         $dateRangeRejected datePremiere/dateEnd were present in $fields but the
     *                                        resulting pair was rejected because it would violate
     *                                        date_end >= date_premiere - neither date changed, see
     *                                        {@see PluginAnimeDataMerger::applyDatePremiereAndEnd()}
     */
    public function __construct(
        public readonly array $unapplied,
        public readonly bool $dateRangeRejected,
    ) {
    }
}
