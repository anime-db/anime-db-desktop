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

namespace App\Service\Catalog;

use App\Entity\SourceUrl;

/**
 * Comparison form of a source url, the value of `anime_source.normalized_url`. It is only for
 * comparing links: the displayed url (`AnimeSource::$url`) is never replaced by it.
 *
 * Site rules, when they appear, belong here and not in {@see SourceUrl::common()}: what the catalog
 * stores for de-duplication must not depend on the set of installed plugins.
 */
final class SourceUrlNormalizer
{
    /** Common steps, then (later) site rules, then the length limit. */
    public function normalize(string $url): string
    {
        $common = SourceUrl::common($url);

        // Site rules are applied to $common here.
        $result = $common;

        return \strlen($result) > SourceUrl::MAX_LENGTH ? $common : $result;
    }
}
