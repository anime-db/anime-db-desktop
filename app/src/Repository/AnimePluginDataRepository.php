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

namespace App\Repository;

use App\Entity\AnimePluginData;
use App\Entity\ValueObject\PluginId;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Plain autowirable wrapper, not a Doctrine `#[ORM\Entity(repositoryClass: ...)]` (see
 * gotchas.md — the latter needs `ContainerRepositoryFactory`, which breaks the tests that stand
 * up a raw EntityManager outside the container). {@see \App\Service\Plugin\PluginDataStore} does
 * not use this class for its own writes: a write that hits an optimistic-lock conflict leaves
 * Doctrine's EntityManager closed, and this repository (like {@see StudioRepository}) is bound
 * to one EntityManager instance for its lifetime, so it would keep querying a closed manager
 * after the first retry. This repository is for plain, non-retrying reads elsewhere in the app.
 */
class AnimePluginDataRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findOneByAnimeAndPlugin(int $animeId, PluginId $pluginId): ?AnimePluginData
    {
        return $this->entityManager->getRepository(AnimePluginData::class)->findOneBy([
            'anime' => $animeId,
            'pluginId' => (string) $pluginId,
        ]);
    }
}
