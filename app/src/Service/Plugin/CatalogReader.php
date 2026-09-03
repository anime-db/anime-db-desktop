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

use AnimeDb\PluginContracts\Catalog\AnimeView;
use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use AnimeDb\PluginContracts\ExternalIdResolutionInterface;
use AnimeDb\PluginContracts\Model\AnimeId;
use AnimeDb\PluginContracts\Model\AnimeType as ContractAnimeType;
use AnimeDb\PluginContracts\Model\GenreCode as ContractGenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode as ContractThemeCode;
use App\Entity\Anime;
use App\Entity\AnimeName;
use App\Entity\AnimeSource;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\SeriesAnime;
use App\Entity\ValueObject\PluginId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Read-only projection of a catalog record's current state, scoped to a single plugin — see
 * {@see DependencyInjection\Compiler\CatalogReaderScopePass}, which constructs one instance per
 * installed plugin and is the only place a plugin ever obtains one, bound to that plugin's own
 * {@see PluginId} (issue #577).
 *
 * Never writes: `externalId` on the returned {@see AnimeView} favours the cached row
 * ({@see Anime::getCachedExternalId()}), a read-only lookup that never talks to the plugin — the
 * fast path a widget rendered on every HTMX request takes. Only when nothing is cached yet does
 * this fall back to a live, equally read-only resolve via {@see $resolver} directly
 * ({@see ExternalIdResolutionInterface::resolveExternalId()} — a local URL parse, not a network
 * call), rather than persisting the result: {@see CatalogReaderInterface} is injected into
 * arbitrary plugin services, including ones the host may call from inside its own unfinished unit
 * of work, so read() must never flush it out from under the caller. Populating the cache row
 * stays the job of the background sweep ({@see \App\MessageHandler\BackfillExternalIdMessageHandler})
 * and of {@see Anime::getExternalId()}'s callers elsewhere. $resolver is null for a plugin with no
 * `app.filler`/`app.sync`/`app.search_by_plugin`-tagged service at all (see the compiler pass), in
 * which case a cache miss stays null — this covers both "genuinely no external id" (a `type:
 * local` plugin) and "an external-id-capable plugin whose cache nobody has populated yet", the
 * latter logged at debug level so it does not read as silent, unexplained emptiness.
 *
 * Fetches a fresh {@see EntityManagerInterface} from {@see ManagerRegistry} on every read() rather
 * than holding one in a property, same reasoning as {@see PluginDataStore}: this instance is
 * shared for the life of the plugin's scope, and any *other* code sharing the same EntityManager
 * that flushes and fails closes it for everyone — a held-onto closed instance would make read()
 * throw for the rest of the process.
 */
final class CatalogReader implements CatalogReaderInterface
{
    public function __construct(
        private readonly PluginId $pluginId,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ?ExternalIdResolutionInterface $resolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function read(AnimeId $anime): ?AnimeView
    {
        $entity = $this->entityManager()->find(Anime::class, $anime->value);
        if (!$entity instanceof Anime) {
            return null;
        }

        return new AnimeView(
            title: $entity->getTitle(),
            alternativeNames: array_map(static fn (AnimeName $name): string => $name->name, $entity->getNames()->toArray()),
            type: ContractAnimeType::from($entity->getType()->value),
            genres: array_map(static fn (GenreCode $code): ContractGenreCode => ContractGenreCode::from($code->value), $entity->getGenreCodes()),
            themes: array_map(static fn (ThemeCode $code): ContractThemeCode => ContractThemeCode::from($code->value), $entity->getThemeCodes()),
            episodesCount: $entity instanceof SeriesAnime ? $entity->getEpisodesCount() : null,
            sources: array_map(static fn (AnimeSource $source): string => $source->url, $entity->getSources()->toArray()),
            externalId: $this->resolveExternalId($entity),
        );
    }

    private function resolveExternalId(Anime $anime): ?string
    {
        $cached = $anime->getCachedExternalId($this->pluginId);
        if ($cached !== null) {
            return $cached;
        }

        if ($this->resolver === null) {
            $this->logger->debug('Catalog record has no cached external id for this plugin, and the plugin has no resolver service to try instead.', [
                'pluginId' => (string) $this->pluginId,
                'animeId' => $anime->id,
            ]);

            return null;
        }

        $sources = array_map(static fn (AnimeSource $source): string => $source->url, $anime->getSources()->toArray());

        try {
            return $this->resolver->resolveExternalId($sources);
        } catch (\Throwable $exception) {
            $this->logger->error('Resolving the external id failed while reading a catalog record.', [
                'pluginId' => (string) $this->pluginId,
                'animeId' => $anime->id,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManagerForClass(Anime::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('No EntityManager is registered for '.Anime::class.'.');
        }

        if (!$entityManager->isOpen()) {
            $this->managerRegistry->resetManager();
            $entityManager = $this->managerRegistry->getManagerForClass(Anime::class);
        }

        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
