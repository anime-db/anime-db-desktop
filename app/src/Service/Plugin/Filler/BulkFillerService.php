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

namespace App\Service\Plugin\Filler;

use AnimeDb\PluginContracts\AnimeType as ContractsAnimeType;
use AnimeDb\PluginContracts\FillerInterface;
use AnimeDb\PluginContracts\PluginAnimeData;
use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\ExternalIdAlreadyClaimedException;
use App\Service\Plugin\FillerRegistry;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Bulk fill-in scenario (issue #227): a Storage scan matched a top-level entry only through a
 * plugin search, with nothing in the local catalog to link it to (see
 * ScanStorageService::linkToChosenCandidate()'s plugin branch) — so a brand-new Anime is created
 * and immediately filled in with everything the plugin's FillerInterface can provide, instead of
 * the title-only placeholder that was the only option before this issue.
 *
 * "Bulk" here specifically means the create path: this service never touches an already
 * existing Anime, so the merge-vs-overwrite distinction PluginAnimeDataMerger enforces never
 * risks clobbering user data — every field it writes lands on a row that did not exist a moment
 * ago.
 * The one plugin used is whichever one produced the match in the first place — the issue's
 * "priority or explicitly user-chosen" plugin selection has no config surface to choose from yet
 * (no plugin management UI exists), so re-using the plugin that already found the title is the
 * only selection available today.
 *
 * A caller that already has an externalId for that plugin (e.g. SearchByPluginChain's own
 * find() call during the storage scan, issue #233) passes it in to skip a redundant find()
 * round trip — findById() is called directly instead. A plugin's find()/findById() throwing is
 * treated the same as it returning nothing: this service falls back to null rather than letting
 * a misbehaving plugin abort the caller's whole operation.
 */
final class BulkFillerService
{
    /** @var array<string, PluginAnimeData|null> findById() results cached for this service's lifetime, keyed by "pluginId:externalId" */
    private array $cache = [];

    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly PluginAnimeDataMerger $merger,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param ?string $externalId already-known external id for $pluginId (e.g. from a
     *                            SearchByPluginChain candidate) — when given (non-empty),
     *                            find() is skipped and findById() is called directly instead
     *
     * @return Anime|null null when no active filler is registered for $pluginId, the plugin's
     *                    own find()/findById() could not resolve anything, or either call threw
     *                    — the caller falls back to its own title-only placeholder in that case
     */
    public function fillNewFromPlugin(PluginId $pluginId, string $name, ?string $externalId = null): ?Anime
    {
        $filler = $this->fillerRegistry->findByPluginId($pluginId);
        if ($filler === null) {
            return null;
        }

        try {
            $resolved = $externalId !== null && $externalId !== ''
                ? $this->resolveKnownExternalId($filler, $externalId)
                : $this->resolve($filler, $name);
        } catch (\Throwable $e) {
            $this->logger->warning('Plugin find()/findById() failed during bulk-fill, falling back to a title-only placeholder.', [
                'pluginId' => (string) $pluginId,
                'exception' => $e,
            ]);

            return null;
        }

        if ($resolved === null) {
            return null;
        }

        [$externalId, $data] = $resolved;

        return $this->build($filler, $pluginId, $externalId, $data);
    }

    /**
     * Same create-and-fill-in as {@see fillNewFromPlugin()}, but for a caller that already
     * knows both the external id and the filler instance to use — pull-sync (issue #257
     * review), which gets $externalId straight from SyncItem and, per the sync-plugin-is-also-
     * a-filler contract (anime-db-plugin-contracts#25), already has the filler instance in
     * hand as the sync plugin itself. Deliberately bypasses FillerRegistry::findByPluginId():
     * that lookup also gates on the plugin's own features.filler toggle, which has nothing to
     * do with a sync plugin's built-in ability to enrich the very list item it just pulled.
     *
     * @return Anime|null null when $filler's own findById() could not resolve $externalId to
     *                    anything — the caller is expected to skip the item entirely rather
     *                    than fall back to a title-only placeholder (issue #257 review)
     */
    public function fillNewFrom(FillerInterface $filler, PluginId $pluginId, string $externalId): ?Anime
    {
        $data = $this->findById($filler, $externalId);

        return $data === null ? null : $this->build($filler, $pluginId, $externalId, $data);
    }

    /**
     * @throws ExternalIdAlreadyClaimedException when a concurrent create flow already
     *                                           claimed ($pluginId, $externalId) — see that
     *                                           exception's docblock for why this is caught
     *                                           here rather than left to the caller's own
     *                                           flush()
     */
    private function build(FillerInterface $filler, PluginId $pluginId, string $externalId, PluginAnimeData $data): Anime
    {
        $anime = $this->instantiate($data->type);
        $anime->setTitle($data->title)->setWatchStatus(WatchStatus::Plan);

        // Flushed alone, before the external id claim below: an autoincrement PK insert is
        // never conflict-prone, so this step is always safe and gives $anime an id.
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $anime->rememberExternalId($pluginId, $externalId);

        try {
            // The only conflict-prone write in this whole flow (issue #297): the
            // anime_external_id UNIQUE(plugin_id, external_id) constraint, not a prior
            // SELECT, is what actually catches a race against another concurrent create.
            // Isolated in its own flush() rather than folded into a caller's batch flush,
            // because a failed flush() closes Doctrine's UnitOfWork for good — bundling it
            // with unrelated pending work would take that down too.
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // The EntityManager is closed now (Doctrine's own reaction to a failed flush) —
            // persist()/flush()/remove() are off the table, but a plain connection read/write
            // still works, so the orphaned Anime row from the flush above is cleaned up via
            // raw SQL instead of $entityManager->remove().
            $winnerId = (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT anime_id FROM anime_external_id WHERE plugin_id = ? AND external_id = ?',
                [(string) $pluginId, $externalId],
            );
            $this->entityManager->getConnection()->delete('anime', ['id' => $anime->id]);

            throw new ExternalIdAlreadyClaimedException($pluginId, $externalId, $winnerId, $e);
        }

        // title/type are already applied above; cover/images stay out of the bulk create path —
        // downloading them needs the anime's own database id (see PluginAnimeDataMerger::applyCover()),
        // which is available by now, but bulk create is deliberately title/metadata-only (issue #227).
        $fields = array_diff($filler->getFillableFields(), ['title', 'type', 'cover', 'images']);
        $this->merger->apply($anime, $data, $fields);

        return $anime;
    }

    /** @return array{0: string, 1: PluginAnimeData}|null */
    private function resolve(FillerInterface $filler, string $name): ?array
    {
        $candidates = $filler->find($name);
        if ($candidates === []) {
            return null;
        }

        return $this->resolveKnownExternalId($filler, $candidates[0]->getExternalId());
    }

    /** @return array{0: string, 1: PluginAnimeData}|null */
    private function resolveKnownExternalId(FillerInterface $filler, string $externalId): ?array
    {
        $data = $this->findById($filler, $externalId);

        return $data === null ? null : [$externalId, $data];
    }

    private function findById(FillerInterface $filler, string $externalId): ?PluginAnimeData
    {
        $cacheKey = $filler::class.':'.$externalId;
        if (\array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        return $this->cache[$cacheKey] = $filler->findById($externalId);
    }

    private function instantiate(?ContractsAnimeType $type): Anime
    {
        $class = $type === null ? TvAnime::class : AnimeType::from($type->value)->entityClass();

        return new $class();
    }
}
