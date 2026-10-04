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
use App\Entity\AnimeExternalId;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\SeriesAnime;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\DownloadAnimeMediaMessage;
use App\Repository\AnimeRepository;
use App\Service\Plugin\Exception\AnimeAlreadyLinkedToDifferentExternalIdException;
use App\Service\Plugin\Exception\ExternalIdAlreadyClaimedException;
use App\Service\Plugin\FillerRegistry;
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
            // moment) — build()/createTitleOnly() link the external id via a DBAL `INSERT ...
            // ON CONFLICT DO NOTHING` rather than an ORM flush (see persistAndLinkExternalId()),
            // so losing this race never closes the EntityManager: resolve() below, and anything
            // the caller does with its result afterwards (including its own flush()), runs on
            // the same EntityManager exactly as it would have if this pair had resolved on the
            // very first check above.
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
     * own docblock), so they are always included — with two exceptions carved out below:
     * 'descriptions' merges by (anime, locale), not by (anime, plugin, locale), so a locale
     * $anime already has non-empty text for is left untouched rather than overwritten by the
     * plugin's own text for that same locale; 'images' is queued through the async `media`
     * message, same as {@see build()}, rather than downloading however many screenshots the
     * plugin returns synchronously in this request.
     *
     * @throws ExternalIdAlreadyClaimedException                when ($pluginId, $externalId)
     *                                                          already belongs to a *different*
     *                                                          Anime — the caller is expected to
     *                                                          surface that as a conflict, not retry
     * @throws AnimeAlreadyLinkedToDifferentExternalIdException when $anime already carries a
     *                                                          different external id for $pluginId —
     *                                                          applying $externalId's data here
     *                                                          would otherwise mix two unrelated
     *                                                          titles' data onto the same record
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

        $cachedExternalId = $anime->getCachedExternalId($pluginId);
        if ($cachedExternalId !== null && $cachedExternalId !== $externalId) {
            throw new AnimeAlreadyLinkedToDifferentExternalIdException($pluginId, $cachedExternalId, $externalId, $anime->id ?? 0);
        }

        $data = $this->safeFindById($filler, $pluginId, $externalId);
        if ($data === null) {
            return FillResult::NotFound;
        }

        if ($cachedExternalId === null) {
            $anime->rememberExternalId($pluginId, $externalId);
        }

        $fields = $this->emptyFillableFields($anime, $filler);
        $this->applyMissingLocaleDescriptions($anime, $data, $fields);

        $result = $this->merger->apply($anime, $data, array_diff($fields, ['descriptions', 'images']));
        $this->dispatchMediaDownloads($anime, $data, $fields, includeCover: false);
        $this->entityManager->flush();

        return match (true) {
            $result->dateRangeRejected => FillResult::DateRangeRejected,
            $result->unapplied !== [] => FillResult::ImageRejected,
            default => FillResult::Applied,
        };
    }

    /**
     * 'descriptions' is excluded from the generic {@see PluginAnimeDataMerger::apply()} call in
     * {@see fillExistingFromPlugin()} because that merger's own applyDescriptions() merges by
     * locale key alone (Anime::setDescription() upserts), overwriting an already-filled-in
     * locale's text outright — exactly the "only empty fields" contract this method promises
     * that the generic path cannot keep for this one field. A locale $anime has no text for yet
     * is still filled in, same as the generic path would have done.
     *
     * @param string[] $fields the same emptyFillableFields() result fillExistingFromPlugin()
     *                         passes everywhere else — checked here only for whether 'descriptions'
     *                         is in it at all (the filler might not declare it fillable)
     */
    private function applyMissingLocaleDescriptions(Anime $anime, PluginAnimeData $data, array $fields): void
    {
        if (!\in_array('descriptions', $fields, true)) {
            return;
        }

        $existingLocales = [];
        foreach ($anime->getDescriptions() as $description) {
            if ($description->description !== '') {
                $existingLocales[$description->locale] = true;
            }
        }

        foreach ($data->descriptions ?? [] as $locale => $text) {
            if (!isset($existingLocales[(string) $locale])) {
                $anime->setDescription((string) $locale, $text);
            }
        }
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
        $result = $this->merger->apply($anime, $data, array_diff($fields, $excludedFromMerge));

        $coverStillNeedsQueueing = !$downloadCoverSynchronously || \in_array('cover', $result->unapplied, true);
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
     * safe and gives $anime an id) and then links $externalId to it via a DBAL `INSERT ... ON
     * CONFLICT DO NOTHING` rather than the ORM (issue #839) — the anime_external_id
     * UNIQUE(plugin_id, external_id) constraint, not a prior SELECT, is what actually catches a
     * race against another concurrent create, same as before, but losing that race here is a
     * plain "0 rows affected", not a failed flush(). An ORM flush() failing for any reason
     * closes Doctrine's UnitOfWork for good (its own documented behavior) — every caller up the
     * chain (the storage scan's own final flush(), the confirm controller's) shares this same
     * EntityManager instance, so that used to take every one of them down with it too. Going
     * around the ORM for this one conflict-prone write keeps the EntityManager open and usable
     * no matter which side of the race this call lands on.
     *
     * @throws ExternalIdAlreadyClaimedException when a concurrent create flow already claimed
     *                                           ($pluginId, $externalId)
     */
    private function persistAndLinkExternalId(Anime $anime, PluginId $pluginId, string $externalId): void
    {
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $inserted = $connection->executeStatement(
            'INSERT INTO anime_external_id (anime_id, plugin_id, external_id) VALUES (?, ?, ?) ON CONFLICT (plugin_id, external_id) DO NOTHING',
            [$anime->id, (string) $pluginId, $externalId],
        );

        if ($inserted === 0) {
            // Lost the race: another create flow's row for this pair already exists. $anime's
            // own row is still a harmless orphan at this point (nothing else references it yet),
            // cleaned up via the same connection rather than $entityManager->remove() — the
            // EntityManager is deliberately never touched by this branch, see this method's own
            // docblock for why that matters to every caller sharing it.
            $winnerId = (int) $connection->fetchOne(
                'SELECT anime_id FROM anime_external_id WHERE plugin_id = ? AND external_id = ?',
                [(string) $pluginId, $externalId],
            );
            $connection->delete('anime', ['id' => $anime->id]);
            $this->entityManager->detach($anime);

            throw new ExternalIdAlreadyClaimedException($pluginId, $externalId, $winnerId);
        }

        // Tells Doctrine's UnitOfWork this row is already in the database (see
        // Anime::attachPersistedExternalId()'s own docblock for why that matters): without it,
        // the OneToMany's cascade:['persist'] would schedule this same row for a second INSERT
        // on the very next flush() — which would also fail the UNIQUE constraint, this time with
        // nothing left to recover from.
        $entry = new AnimeExternalId($anime, $pluginId, $externalId);
        $this->entityManager->getUnitOfWork()->registerManaged(
            $entry,
            // The identifier array becomes a raw identity-map hash key (Doctrine joins it with a
            // plain `implode(' ', ...)`, see UnitOfWork::getIdHashByIdentifier()) — it needs
            // $anime's scalar PK, not the object itself, unlike the "original data" snapshot
            // below, whose 'anime' value is compared against the entity's actual property value
            // on the next flush() and must therefore be the same object reference.
            ['anime' => $anime->id, 'pluginId' => (string) $pluginId],
            ['anime' => $anime, 'pluginId' => (string) $pluginId, 'externalId' => $externalId],
        );
        $anime->attachPersistedExternalId($entry);
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
