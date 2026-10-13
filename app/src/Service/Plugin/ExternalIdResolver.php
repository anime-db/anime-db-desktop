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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\ExternalIdResolutionInterface;
use App\Entity\Anime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The one place that turns a plugin-recognised external id into a cached AnimeExternalId row.
 *
 * The anime_external_id table is unique by (plugin_id, external_id), so caching an id that is
 * already pinned to another anime makes the next flush() fail and close the EntityManager. Every
 * lazy resolution therefore goes through here: an id owned by another anime is not cached, the
 * call returns null, and a warning is logged. A negative result is never cached.
 */
final class ExternalIdResolver
{
    /**
     * Ids cached in memory but not flushed yet, per EntityManager: the owner lookup is a DQL query
     * and does not see them. Weak references, so a finished unit of work does not pin its anime.
     *
     * @var \WeakMap<object, array<string, \WeakReference<Anime>>>
     */
    private \WeakMap $pending;

    /** Stands for the default EntityManager in {@see $pending}. */
    private readonly \stdClass $defaultScope;

    public function __construct(
        private readonly AnimeRepository $animeRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->pending = new \WeakMap();
        $this->defaultScope = new \stdClass();
    }

    /**
     * The cached id for $pluginId, or the one $plugin recognises in the anime's source URLs.
     *
     * @param ?EntityManagerInterface $entityManager the manager $anime is attached to, when it is
     *                                               not the default one
     */
    public function resolve(Anime $anime, PluginId $pluginId, ExternalIdResolutionInterface $plugin, ?EntityManagerInterface $entityManager = null): ?string
    {
        $cached = $anime->getCachedExternalId($pluginId);
        if ($cached !== null) {
            return $cached;
        }

        $urls = array_map(static fn ($source): string => $source->url, $anime->getSources()->toArray());
        $externalId = $plugin->resolveExternalId($urls);
        if ($externalId === null) {
            return null;
        }

        return $this->claim($anime, $pluginId, $externalId, $entityManager);
    }

    /**
     * Caches $externalId for $anime unless another anime already holds it for $pluginId.
     *
     * @return ?string $externalId, or null when it is pinned to another anime
     */
    public function claim(Anime $anime, PluginId $pluginId, string $externalId, ?EntityManagerInterface $entityManager = null): ?string
    {
        if ($anime->getCachedExternalId($pluginId) === $externalId) {
            return $externalId;
        }

        $repository = $entityManager !== null ? new AnimeRepository($entityManager) : $this->animeRepository;
        $scope = $entityManager ?? $this->defaultScope;
        $key = $pluginId.'|'.$externalId;
        $owner = $repository->resolve($pluginId, $externalId) ?? $this->pendingOwner($scope, $key, $pluginId, $externalId, $anime);
        if ($owner !== null && $owner !== $anime && ($owner->id === null || $owner->id !== $anime->id)) {
            $this->logger->warning('Possible duplicate anime: external id {externalId} of plugin {pluginId} is already pinned to anime #{ownerId}, not caching it for anime #{animeId}.', [
                'pluginId' => (string) $pluginId,
                'externalId' => $externalId,
                'animeId' => $anime->id,
                'ownerId' => $owner->id,
            ]);

            return null;
        }

        $anime->rememberExternalId($pluginId, $externalId);
        $claims = $this->pending[$scope] ?? [];
        $claims[$key] = \WeakReference::create($anime);
        $this->pending[$scope] = $claims;

        return $externalId;
    }

    private function pendingOwner(object $scope, string $key, PluginId $pluginId, string $externalId, Anime $anime): ?Anime
    {
        $holder = ($this->pending[$scope][$key] ?? null)?->get();
        if ($holder === null || $holder === $anime || ($holder->id !== null && $holder->id === $anime->id) || $holder->getCachedExternalId($pluginId) !== $externalId) {
            return null;
        }

        return $holder;
    }
}
