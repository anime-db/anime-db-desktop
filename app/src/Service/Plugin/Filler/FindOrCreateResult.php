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

use App\Entity\Anime;

/**
 * Outcome of {@see BulkFillerService::findOrCreateFromPlugin()} (issue #832): whether $anime was
 * an already-known catalog record resolved by (pluginId, externalId), or a brand-new one this
 * call just created. A caller linking $anime to a storage/path needs to tell the two apart — an
 * already-known record that is linked elsewhere is a conflict to report, not a storage write to
 * perform; a newly created one has nothing to conflict with yet.
 */
final class FindOrCreateResult
{
    private function __construct(
        public readonly Anime $anime,
        public readonly bool $wasFound,
        public readonly bool $filledFromPlugin,
    ) {
    }

    public static function found(Anime $anime): self
    {
        return new self($anime, true, true);
    }

    public static function created(Anime $anime, bool $filledFromPlugin): self
    {
        return new self($anime, false, $filledFromPlugin);
    }
}
