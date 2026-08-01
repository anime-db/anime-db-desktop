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

namespace App\Service\Plugin\Exception;

use App\Entity\ValueObject\PluginId;

/**
 * Thrown by {@see \App\Service\Plugin\Filler\BulkFillerService::build()} (issue #297) when a
 * concurrent create flow already claimed ($pluginId, $externalId) — the anime_external_id
 * UNIQUE(plugin_id, external_id) constraint, not a prior SELECT, is what actually decides
 * this race. $animeId is the winner's id, resolved right here rather than left for the
 * catching caller to re-query, so a caller reacting to this exception (PullSyncService)
 * never needs a second resolve() round trip to fall onto the winning record.
 */
final class ExternalIdAlreadyClaimedException extends \RuntimeException
{
    public function __construct(public readonly PluginId $pluginId, public readonly string $externalId, public readonly int $animeId, ?\Throwable $previous = null)
    {
        parent::__construct(
            \sprintf('External id "%s" for plugin "%s" was already claimed by anime #%d.', $externalId, $pluginId, $animeId),
            previous: $previous,
        );
    }
}
