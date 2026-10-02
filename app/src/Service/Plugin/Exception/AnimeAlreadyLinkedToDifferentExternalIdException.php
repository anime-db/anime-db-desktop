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

namespace App\Service\Plugin\Exception;

use App\Entity\ValueObject\PluginId;

/**
 * Thrown by {@see \App\Service\Plugin\Filler\BulkFillerService::fillExistingFromPlugin()}
 * (issue #839) when the target Anime already carries a *different* external id for the same
 * plugin — distinct from {@see ExternalIdAlreadyClaimedException}, which fires when the
 * incoming external id itself already belongs to a *different* Anime. Applying the incoming
 * id's plugin data onto a record linked to another id would mix two unrelated titles' data
 * together, so this is refused outright rather than silently relinking or merging.
 */
final class AnimeAlreadyLinkedToDifferentExternalIdException extends \RuntimeException
{
    public function __construct(public readonly PluginId $pluginId, public readonly string $existingExternalId, public readonly string $attemptedExternalId, public readonly int $animeId)
    {
        parent::__construct(\sprintf(
            'Anime #%d is already linked to external id "%s" for plugin "%s" and cannot also be linked to "%s".',
            $animeId,
            $existingExternalId,
            $pluginId,
            $attemptedExternalId,
        ));
    }
}
