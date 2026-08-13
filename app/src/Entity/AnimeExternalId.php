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

namespace App\Entity;

use App\Entity\ValueObject\PluginId;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistent index of a plugin's external id for an Anime (issue #297), replacing the
 * unindexed metadata['external_id'][pluginId] blob a resolve/dedup lookup used to
 * json_extract() over.
 *
 * PK is (anime_id, plugin_id) — at most one external id per plugin per anime, the same
 * shape the old metadata map enforced. The UNIQUE(plugin_id, external_id) index below is
 * the actual concurrency guard: two concurrent create flows racing to link the same
 * source record both attempt this insert, and the constraint — not a prior SELECT — is
 * what decides the winner (see BulkFillerService::build()).
 *
 * anime_id cascades on Anime removal, but plugin_id is not a foreign key to anything: an
 * external id is stable on the source and must survive a plugin reinstall so the record
 * re-links instantly instead of re-resolving (issue #297, deliberate).
 */
#[ORM\Entity]
#[ORM\Table(name: 'anime_external_id')]
#[ORM\UniqueConstraint(name: 'UNIQ_ANIME_EXTERNAL_ID_PLUGIN_EXTERNAL', columns: ['plugin_id', 'external_id'])]
class AnimeExternalId
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Anime::class, inversedBy: 'externalIds')]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly Anime $anime;

    #[ORM\Id]
    #[ORM\Column(name: 'plugin_id', length: 64)]
    public readonly string $pluginId;

    #[ORM\Column(name: 'external_id', length: 255)]
    public readonly string $externalId;

    public function __construct(Anime $anime, PluginId $pluginId, string $externalId)
    {
        $this->anime = $anime;
        $this->pluginId = (string) $pluginId;
        $this->externalId = $externalId;
    }
}
