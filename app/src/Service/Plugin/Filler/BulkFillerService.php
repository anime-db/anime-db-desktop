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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Model\AnimeType as ContractsAnimeType;
use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\SeriesAnime;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\DownloadAnimeMediaMessage;
use App\Repository\AnimeRepository;
use App\Service\Plugin\Exception\ExternalIdAlreadyClaimedException;
use App\Service\Plugin\FillerRegistry;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Bulk fill-in scenario (issue #227): a Storage scan matched a top-level entry only through a
 * plugin search, with nothing in the local catalog to link it to (see
 * ScanStorageService::linkToChosenCandidate()'s plugin branch) — so a brand-new Anime is created
 * and immediately filled in with everything the plugin's FillerInterface can provide, instead of
 * the title-only placeholder that was the only option before this issue.
 *
 * {@see self::findOrCreateFromPlugin()} (issue #832) is the "find or create by (pluginId,
 * externalId)" entry point every caller that already knows a candidate's external id should use
 * — it checks {@see AnimeRepository::resolve()} first, so an already-catalogued record is
 * returned instead of a second one being created (and instead of the create path's own
 * anime_external_id UNIQUE constraint ever being hit in the common case). {@see
 * self::fillNewFromPlugin()}/{@see self::fillNewFrom()} below are the older, create-only entry
 * points that skip that check — still used by callers that have already resolved their own
 * duplicate first (pull-sync's own indexByExternalId() pass).
 *
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
    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly PluginAnimeDataMerger $merger,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly AnimeRepository $animeRepository,
        private readonly CachedFillerLookup $lookup,
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
                ? $this->resolveKnownExternalId($filler, $pluginId, $externalId)
                : $this->resolve($filler, $pluginId, $name);
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
        $data = $this->lookup->findById($filler, $pluginId, $externalId);

        return $data === null ? null : $this->build($filler, $pluginId, $externalId, $data);
    }

    /**
     * "Find or create" by (pluginId, externalId) (issue #832): checks {@see
     * AnimeRepository::resolve()} before ever creating anything, so a candidate whose external
     * id already belongs to a catalog record never reaches the create path's UNIQUE constraint
     * in the first place — that constraint is still the authority for a genuine concurrent
     * create race (two requests resolving "not found" for the same pair at once), but the
     * common "already in the catalog" case no longer depends on losing that race to be caught.
     *
     * $externalId === '' (a search-only candidate with no id to dedupe on, e.g. a plain
     * SearchByPluginInterface match the storage scan already allowed before this issue) skips
     * the whole find-or-create dance: a title-only Anime is created with no external id
     * remembered, same as a caller with no plugin match at all.
     *
     * A found record's own storage/storage_path is left untouched here — linking it to the
     * caller's storage/path (and detecting a conflict if it is already linked elsewhere) is the
     * caller's job (ScanStorageService), not this service's.
     *
     * @param bool $downloadCoverSynchronously true only for a single-candidate confirmation
     *                                         (issue #832 point 5): the storage scan's own
     *                                         bulk/auto-link path must not block on a plugin's
     *                                         media host for however many items it is
     *                                         processing, but a user confirming exactly one
     *                                         candidate is worth the wait for its cover, with
     *                                         the usual plugin media HTTP client timeout
     *                                         bounding how long that wait can be. A failed
     *                                         synchronous download still falls back to the
     *                                         async `media` queue, same as every other cover.
     */
    public function findOrCreateFromPlugin(PluginId $pluginId, string $externalId, string $name, bool $downloadCoverSynchronously = false): FindOrCreateResult
    {
        if ($externalId === '') {
            $anime = new TvAnime();
            $anime->setTitle($name)->setWatchStatus(WatchStatus::Plan);
            $this->entityManager->persist($anime);

            return FindOrCreateResult::created($anime, filledFromPlugin: false);
        }

        $existing = $this->animeRepository->resolve($pluginId, $externalId);
        if ($existing !== null) {
            return FindOrCreateResult::found($existing);
        }

        $filler = $this->fillerRegistry->findByPluginId($pluginId);
        $data = $filler !== null ? $this->safeFindById($filler, $pluginId, $externalId) : null;

        try {
            $anime = $data !== null
                ? $this->build($filler, $pluginId, $externalId, $data, $downloadCoverSynchronously)
                : $this->createTitleOnly($pluginId, $externalId, $name);
        } catch (ExternalIdAlreadyClaimedException $conflict) {
            // A genuine concurrent create race (e.g. the storage scan's own auto-link and a
            // user's confirm request both resolving "not found" for this pair at the same
            // moment) — build()/createTitleOnly() already isolated the UNIQUE-violating flush
            // into its own try/catch so the only side effect here is that EntityManager being
            // closed (Doctrine's reaction to any failed flush, not just this one). A plain SELECT
            // still works on a closed EntityManager (only persist()/flush()/remove()/refresh()
            // check EntityManager::isOpen(), see Doctrine's own EntityManager::errorIfClosed()
            // callers) — resolve() below is read-only, so this stays within the "no persist/
            // flush into a closed EntityManager" invariant the caller relies on.
            $winner = $this->animeRepository->resolve($pluginId, $externalId);
            if ($winner === null) {
                // Unreachable in practice: the UNIQUE violation means a row for this pair exists.
                throw $conflict;
            }

            return FindOrCreateResult::found($winner);
        }

        return FindOrCreateResult::created($anime, filledFromPlugin: $data !== null);
    }

    /**
     * "Fill existing record from a selected external id" (issue #832 point 10), the basis for
     * a future "search in plugins" screen (not implemented here): links $externalId to an
     * already-persisted $anime — with the same conflict protection as {@see
     * findOrCreateFromPlugin()} — and applies only the fields {@see PluginAnimeDataMerger}
     * would otherwise overwrite outright that are still empty on $anime. Collection fields
     * (alternative names, genres, ...) already merge rather than overwrite (see that class's
     * own docblock), so they are always included: nothing the user already entered is at risk.
     *
     * @throws ExternalIdAlreadyClaimedException when ($pluginId, $externalId) already belongs
     *                                           to a *different* Anime — the caller is expected
     *                                           to surface that as a conflict, not retry
     */
    public function fillExistingFromPlugin(Anime $anime, PluginId $pluginId, string $externalId): FillResult
    {
        $filler = $this->fillerRegistry->findByPluginId($pluginId);
        if ($filler === null) {
            return FillResult::NotFound;
        }

        $owner = $this->animeRepository->resolve($pluginId, $externalId);
        if ($owner !== null && $owner !== $anime) {
            throw new ExternalIdAlreadyClaimedException($pluginId, $externalId, $owner->id ?? 0);
        }

        $data = $this->safeFindById($filler, $pluginId, $externalId);
        if ($data === null) {
            return FillResult::NotFound;
        }

        if ($anime->getCachedExternalId($pluginId) === null) {
            $anime->rememberExternalId($pluginId, $externalId);
        }

        $fields = $this->emptyFillableFields($anime, $filler);
        $unapplied = $this->merger->apply($anime, $data, $fields);
        $this->entityManager->flush();

        return $unapplied === [] ? FillResult::Applied : FillResult::ImageRejected;
    }

    /**
     * @throws ExternalIdAlreadyClaimedException when a concurrent create flow already
     *                                           claimed ($pluginId, $externalId) — see that
     *                                           exception's docblock for why this is caught
     *                                           here rather than left to the caller's own
     *                                           flush()
     */
    private function build(FillerInterface $filler, PluginId $pluginId, string $externalId, PluginAnimeData $data, bool $downloadCoverSynchronously = false): Anime
    {
        $anime = $this->instantiate($data->type);
        $anime->setTitle($data->title)->setWatchStatus(WatchStatus::Plan);

        $this->persistAndLinkExternalId($anime, $pluginId, $externalId);

        // title/type are already applied above. cover/images are excluded from merger->apply()
        // (and queued instead) unless $downloadCoverSynchronously asked for cover to be fetched
        // right away — see findOrCreateFromPlugin()'s docblock for when that happens.
        $fields = array_diff($filler->getFillableFields(), ['title', 'type']);
        $excludedFromMerge = $downloadCoverSynchronously ? ['images'] : ['cover', 'images'];
        $unapplied = $this->merger->apply($anime, $data, array_diff($fields, $excludedFromMerge));

        $coverStillNeedsQueueing = !$downloadCoverSynchronously || \in_array('cover', $unapplied, true);
        $this->dispatchMediaDownloads($anime, $data, $fields, $coverStillNeedsQueueing);

        return $anime;
    }

    private function createTitleOnly(PluginId $pluginId, string $externalId, string $name): Anime
    {
        $anime = new TvAnime();
        $anime->setTitle($name)->setWatchStatus(WatchStatus::Plan);

        $this->persistAndLinkExternalId($anime, $pluginId, $externalId);

        return $anime;
    }

    /**
     * Persists $anime (an autoincrement PK insert, never conflict-prone, so this step is always
     * safe and gives $anime an id) and then links $externalId to it in its own, isolated
     * flush() — the only conflict-prone write in this whole flow (issue #297): the
     * anime_external_id UNIQUE(plugin_id, external_id) constraint, not a prior SELECT, is what
     * actually catches a race against another concurrent create. Isolated in its own flush()
     * rather than folded into a caller's batch flush, because a failed flush() closes
     * Doctrine's UnitOfWork for good — bundling it with unrelated pending work would take that
     * down too.
     *
     * @throws ExternalIdAlreadyClaimedException when a concurrent create flow already claimed
     *                                           ($pluginId, $externalId)
     */
    private function persistAndLinkExternalId(Anime $anime, PluginId $pluginId, string $externalId): void
    {
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $anime->rememberExternalId($pluginId, $externalId);

        try {
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
    }

    /**
     * @param string[] $fields the same fillable-fields list passed to $this->merger->apply() —
     *                         used here only to check whether the filler actually declares
     *                         'cover'/'images' as fillable, same gate merger->apply() itself
     *                         would have applied had they not been carved out above
     */
    private function dispatchMediaDownloads(Anime $anime, PluginAnimeData $data, array $fields, bool $includeCover): void
    {
        $animeId = $anime->id ?? throw new \LogicException('Anime must have an id before its media downloads can be queued.');

        if ($includeCover && \in_array('cover', $fields, true) && $data->cover !== null) {
            $this->messageBus->dispatch(new DownloadAnimeMediaMessage($animeId, $data->cover, true));
        }

        if (\in_array('images', $fields, true)) {
            foreach ($data->images ?? [] as $url) {
                $this->messageBus->dispatch(new DownloadAnimeMediaMessage($animeId, $url, false));
            }
        }
    }

    /** @return array{0: string, 1: PluginAnimeData}|null */
    private function resolve(FillerInterface $filler, PluginId $pluginId, string $name): ?array
    {
        $candidates = $filler->find($name);
        if ($candidates === []) {
            return null;
        }

        return $this->resolveKnownExternalId($filler, $pluginId, $candidates[0]->getExternalId());
    }

    /** @return array{0: string, 1: PluginAnimeData}|null */
    private function resolveKnownExternalId(FillerInterface $filler, PluginId $pluginId, string $externalId): ?array
    {
        $data = $this->lookup->findById($filler, $pluginId, $externalId);

        return $data === null ? null : [$externalId, $data];
    }

    private function safeFindById(FillerInterface $filler, PluginId $pluginId, string $externalId): ?PluginAnimeData
    {
        try {
            return $this->lookup->findById($filler, $pluginId, $externalId);
        } catch (\Throwable $e) {
            $this->logger->warning('Plugin findById() failed during bulk-fill find-or-create, falling back to a title-only placeholder with the external id preserved.', [
                'pluginId' => (string) $pluginId,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * The subset of $filler->getFillableFields() worth passing to {@see PluginAnimeDataMerger::apply()}
     * for an already-persisted Anime that must not lose user-entered data: collection fields
     * always merge (never overwrite, see that class's docblock) so they are always included;
     * the handful of scalar fields it overwrites outright are included only when still empty on
     * $anime. 'title'/'type' are never included — an existing record's title/type is left
     * exactly as the user set it.
     *
     * @return list<string>
     */
    private function emptyFillableFields(Anime $anime, FillerInterface $filler): array
    {
        $fillable = array_diff($filler->getFillableFields(), ['title', 'type']);

        return array_values(array_filter($fillable, fn (string $field): bool => match ($field) {
            'datePremiere' => $anime->getDatePremiere() === null,
            'dateEnd' => $anime->getDateEnd() === null,
            'durationMinutes' => $anime->getDurationMinutes() === null,
            'demographic' => $anime->getDemographic() === null,
            'episodesCount' => !$anime instanceof SeriesAnime || $anime->getEpisodesCount() === null,
            'cover' => $anime->getCover() === null,
            default => true,
        }));
    }

    private function instantiate(?ContractsAnimeType $type): Anime
    {
        $class = $type === null ? TvAnime::class : AnimeType::from($type->value)->entityClass();

        return new $class();
    }
}
