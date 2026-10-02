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

use AnimeDb\PluginContracts\ExternalIdResolutionInterface;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ProductionStatus;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidAnimeTypeMigrationException;
use App\Entity\Exception\InvalidCountryCodeException;
use App\Entity\Exception\InvalidDateRangeException;
use App\Entity\Exception\InvalidDurationException;
use App\Entity\Exception\InvalidNameException;
use App\Entity\Exception\InvalidWatchStatusException;
use App\Entity\ValueObject\PluginId;
use App\Entity\ValueObject\Rating;
use App\Event\WatchProgressChangedManuallyEvent;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'anime')]
#[ORM\UniqueConstraint(name: 'UNIQ_ANIME_STORAGE_STORAGE_PATH', fields: ['storage', 'storagePath'])]
#[ORM\Index(name: 'IDX_ANIME_STORAGE', fields: ['storage'])]
#[ORM\Index(name: 'IDX_ANIME_NORMALIZED_TITLE', fields: ['normalizedTitle'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', length: 16, enumType: AnimeType::class)]
#[ORM\DiscriminatorMap([
    'movie' => MovieAnime::class,
    'tv' => TvAnime::class,
    'ova' => OvaAnime::class,
    'ona' => OnaAnime::class,
    'special' => SpecialAnime::class,
    'music' => MusicAnime::class,
])]
abstract class Anime implements AggregateRootInterface
{
    use AggregateRootTrait;

    /**
     * Fallback locale for getSummary() when the requested UI locale has no description.
     */
    private const FALLBACK_LOCALE = 'en';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Primary display title (fallback while the anime_name records are not filled in yet).
     */
    #[ORM\Column(length: 256)]
    private string $title;

    /**
     * NameNormalizer::normalize($title), kept in sync by setTitle(). Persisted (not
     * computed on read) so AnimeRepository can match against it with a plain indexed
     * column comparison instead of normalizing title/name in SQL on every query.
     */
    #[ORM\Column(length: 256, options: ['default' => ''])]
    private string $normalizedTitle;

    #[ORM\Column(type: 'unix_timestamp', nullable: true)]
    private ?\DateTimeImmutable $datePremiere = null;

    #[ORM\Column(type: 'unix_timestamp', nullable: true)]
    private ?\DateTimeImmutable $dateEnd = null;

    /**
     * Duration of a single watch unit: an episode's length on SeriesAnime, the whole runtime on MovieAnime.
     */
    #[ORM\Column(nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(length: 16, enumType: WatchStatus::class)]
    private WatchStatus $watchStatus;

    #[ORM\Column(type: 'rating', nullable: true)]
    private ?Rating $userRating = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** @var list<string>|null ISO 3166-1 alpha-2 codes */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $countries = null;

    /**
     * Relative path to the cover file under %AppData%/media/{id}/, resolved via app-media://
     * (issue #68). Unrelated to $storage/$storagePath, which point at the source video file.
     */
    #[ORM\Column(length: 256, nullable: true)]
    private ?string $cover = null;

    #[ORM\ManyToOne(targetEntity: Storage::class)]
    #[ORM\JoinColumn(name: 'storage_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Storage $storage = null;

    /**
     * Name of the top-level file or folder inside $storage->getPath() that this anime is
     * linked to (the scanner only ever looks one level deep — see Таск 3). Not a full
     * path: $storage->getPath() can change (drive letter/device swap), so the full path
     * is composed at render time as $storage->getPath() . $storagePath, the same relative
     * scheme already used by $cover/AnimeImage::$source under %AppData%/media/{id}/.
     */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $storagePath = null;

    /**
     * Unlike $genres/$themes, MAL/Shikimori titles carry at most one demographic in
     * practice, so this is a plain nullable field, not a collection (see Demographic).
     */
    #[ORM\Column(length: 16, enumType: Demographic::class, nullable: true)]
    private ?Demographic $demographic = null;

    #[ORM\Column(type: 'unix_timestamp')]
    private \DateTimeImmutable $dateAdd;

    #[ORM\Column(type: 'unix_timestamp')]
    private \DateTimeImmutable $dateUpdate;

    /**
     * Time of the last change to the (watchStatus, watchedEpisodes) projection, the unit the
     * sync reconciliation snapshot (anime_sync_state, issue #365) diffs against — separate from
     * $dateUpdate, which bumps on every field touch (title edit, rating, ...), not just watch
     * progress. Written by both applyWatchProgress() (the sync-apply path, stamped with the
     * source's own $updatedAt) and changeWatchStatusManually()/changeWatchedEpisodesManually()
     * (the manual-edit path, issue #371, stamped with now() — issue #366's "ручная правка:
     * watchProgressUpdatedAt := now()"). A plain setWatchStatus()/setWatchedEpisodes() call
     * (initial creation, e.g. AnimeNewController/BulkFillerService) leaves it untouched: a
     * brand-new anime has no watch progress history yet to timestamp.
     * Nullable because existing rows only get it via the Version20260812000000 backfill and a
     * freshly created Anime has no watch progress history yet.
     */
    #[ORM\Column(type: 'unix_timestamp', nullable: true)]
    private ?\DateTimeImmutable $watchProgressUpdatedAt = null;

    /**
     * Set by applyWatchProgress() when it rejects an incoming (watchStatus, watchedEpisodes)
     * pair because applying it would violate a local invariant (InvalidWatchStatusException/
     * InvalidEpisodeCountException) — cleared automatically the next time applyWatchProgress()
     * succeeds. A one-shot marker rather than a running log: the future sync engine is expected
     * to raise a review item off it once per rejection (not on every sync attempt) and clear it
     * once surfaced, see issue #365's "не долбить каждый синк, не морозить невидимо".
     */
    #[ORM\Column(type: 'unix_timestamp', nullable: true)]
    private ?\DateTimeImmutable $watchProgressRejectedAt = null;

    /** @var Collection<int, AnimeGenre> */
    #[ORM\OneToMany(mappedBy: 'anime', targetEntity: AnimeGenre::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $genres;

    /** @var Collection<int, AnimeTheme> */
    #[ORM\OneToMany(mappedBy: 'anime', targetEntity: AnimeTheme::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $themes;

    /** @var Collection<int, Studio> */
    #[ORM\ManyToMany(targetEntity: Studio::class, inversedBy: 'animes')]
    #[ORM\JoinTable(name: 'anime_studios')]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'studio_id', referencedColumnName: 'id', onDelete: 'RESTRICT')]
    private Collection $studios;

    /** @var Collection<int, Label> */
    #[ORM\ManyToMany(targetEntity: Label::class, inversedBy: 'animes')]
    #[ORM\JoinTable(name: 'anime_labels')]
    #[ORM\JoinColumn(name: 'anime_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'label_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $labels;

    /** @var Collection<int, AnimeName> */
    #[ORM\OneToMany(mappedBy: 'anime', targetEntity: AnimeName::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $names;

    /** @var Collection<int, AnimeImage> */
    #[ORM\OneToMany(mappedBy: 'anime', targetEntity: AnimeImage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $images;

    /** @var Collection<int, AnimeSource> */
    #[ORM\OneToMany(mappedBy: 'anime', targetEntity: AnimeSource::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $sources;

    /** @var Collection<int, AnimeExternalId> */
    #[ORM\OneToMany(mappedBy: 'anime', targetEntity: AnimeExternalId::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $externalIds;

    /**
     * One row per locale (issue #298). Not eagerly joined/selected by catalog list
     * queries (AnimeRepository::findByFilter()) — Doctrine only loads this collection
     * lazily, the first time getSummary()/getDescriptions() is called, which today only
     * happens on the anime detail page (AnimeViewFactory::serialize()).
     *
     * @var Collection<int, AnimeDescription>
     */
    #[ORM\OneToMany(mappedBy: 'anime', targetEntity: AnimeDescription::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $descriptions;

    public function __construct()
    {
        $this->genres = new ArrayCollection();
        $this->themes = new ArrayCollection();
        $this->studios = new ArrayCollection();
        $this->labels = new ArrayCollection();
        $this->names = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->sources = new ArrayCollection();
        $this->externalIds = new ArrayCollection();
        $this->descriptions = new ArrayCollection();
        $this->dateAdd = new \DateTimeImmutable();
        $this->dateUpdate = new \DateTimeImmutable();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidNameException('title must not be empty');
        }

        $this->title = $title;
        $this->normalizedTitle = NameNormalizer::normalize($title);

        return $this;
    }

    public function getDatePremiere(): ?\DateTimeImmutable
    {
        return $this->datePremiere;
    }

    public function setDatePremiere(?\DateTimeImmutable $datePremiere): self
    {
        $this->assertDateRange($datePremiere, $this->dateEnd);
        $this->datePremiere = $datePremiere;

        return $this;
    }

    public function getDateEnd(): ?\DateTimeImmutable
    {
        return $this->dateEnd;
    }

    public function setDateEnd(?\DateTimeImmutable $dateEnd): self
    {
        $this->assertDateRange($this->datePremiere, $dateEnd);
        $this->dateEnd = $dateEnd;

        return $this;
    }

    private function assertDateRange(?\DateTimeImmutable $datePremiere, ?\DateTimeImmutable $dateEnd): void
    {
        if ($datePremiere !== null && $dateEnd !== null && $dateEnd < $datePremiere) {
            throw new InvalidDateRangeException('date_end must not be earlier than date_premiere');
        }
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(?int $durationMinutes): self
    {
        if ($durationMinutes !== null && $durationMinutes <= 0) {
            throw new InvalidDurationException('duration_minutes must be greater than zero');
        }

        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    public function getWatchStatus(): WatchStatus
    {
        return $this->watchStatus;
    }

    public function setWatchStatus(WatchStatus $watchStatus): self
    {
        if ($watchStatus === WatchStatus::Completed
            && $this->getProductionStatus() !== ProductionStatus::Released) {
            throw new InvalidWatchStatusException('Cannot mark as completed while the anime is still airing or announced');
        }

        $this->watchStatus = $watchStatus;

        return $this;
    }

    /**
     * The manual-edit counterpart of setWatchStatus() (issue #371): used by
     * AnimeEditableController wherever a user edits an existing anime's watch status, so a
     * WatchProgressChangedManuallyEvent can drive the push-on-edit sync trigger — replacing
     * the old Doctrine preUpdate listener (AnimeSyncPushListener), which could not distinguish a
     * user's edit from Anime::applyWatchProgress()'s own writes (the sync-apply path, which
     * deliberately never calls this method).
     *
     * AnimeNewController deliberately does NOT call this on create: the old preUpdate listener
     * never fired on INSERT either, and a brand-new anime has no source link yet for push to act
     * on, so it uses the plain setWatchStatus() instead, same as the other creation paths
     * (BulkFillerService, ScanStorageService, SampleAnimeSeeder, migrate()).
     *
     * isset() rather than a direct read of $this->watchStatus: the typed property has no default,
     * so reading it directly on the very first assignment would throw instead of reporting
     * "no previous value".
     */
    public function changeWatchStatusManually(WatchStatus $watchStatus): self
    {
        $previous = isset($this->watchStatus) ? $this->watchStatus : null;
        $this->setWatchStatus($watchStatus);

        if ($this->watchStatus !== $previous) {
            $current = $this->watchStatus;
            $this->touchWatchProgress(new \DateTimeImmutable());
            $this->recordThat(fn (): WatchProgressChangedManuallyEvent => new WatchProgressChangedManuallyEvent(
                $this->id,
                $current,
                $previous,
            ));
        }

        return $this;
    }

    public function getWatchProgressUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->watchProgressUpdatedAt;
    }

    public function getWatchProgressRejectedAt(): ?\DateTimeImmutable
    {
        return $this->watchProgressRejectedAt;
    }

    /**
     * Applies a (watchStatus, watchedEpisodes) pair as a single reconciled fact rather than two
     * independent field writes (issue #365): the domain already couples them (setWatchedEpisodes()
     * derives a status, setWatchStatus(Completed) forces watchedEpisodes to episodesCount), so a
     * sync source's progress update has to go through the same coupling atomically, or a rejected
     * half of the pair could leave the entity in a state the source never actually sent.
     * SeriesAnime overrides this to also apply $watchedEpisodes, in episodes-then-status order.
     *
     * $watchedEpisodes is accepted here only so the signature matches the SeriesAnime override;
     * Anime itself has no episode axis, so it is ignored.
     *
     * A source pair that violates a local invariant (Completed while not yet released) does not
     * throw out of here — that would abort an entire sync run over one bad item. The pair is left
     * unapplied and $watchProgressRejectedAt is flagged instead; see that property's docblock.
     */
    public function applyWatchProgress(WatchStatus $status, ?int $watchedEpisodes, \DateTimeImmutable $updatedAt): self
    {
        try {
            $this->setWatchStatus($status);
        } catch (InvalidWatchStatusException) {
            $this->flagWatchProgressRejected();

            return $this;
        }

        $this->touchWatchProgress($updatedAt);

        return $this;
    }

    /**
     * Deliberately the last statement on every applyWatchProgress() success path (base and
     * SeriesAnime override alike): it must record the source's own $updatedAt, not a "now" a
     * setter along the way might stamp for an unrelated reason — running it after every mutating
     * setter has already succeeded is what guarantees that.
     */
    protected function touchWatchProgress(\DateTimeImmutable $updatedAt): void
    {
        $this->watchProgressUpdatedAt = $updatedAt;
        $this->watchProgressRejectedAt = null;
    }

    protected function flagWatchProgressRejected(): void
    {
        $this->watchProgressRejectedAt = new \DateTimeImmutable();
    }

    public function getUserRating(): ?Rating
    {
        return $this->userRating;
    }

    public function setUserRating(?Rating $userRating): self
    {
        $this->userRating = $userRating;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    abstract public function getType(): AnimeType;

    /**
     * Recreates $source under a different concrete class, the only mechanism available for
     * changing type: Doctrine's single-table discriminator is fixed per row, so switching
     * class requires a new row (a fresh PK) rather than an in-place discriminator update.
     *
     * A no-op migration to the source's own type (e.g. Tv => Tv) is rejected below; any
     * other target, whether crossing the Movie/Series boundary or between SeriesAnime
     * leaves (e.g. Tv => Ova), is allowed.
     *
     * Only builds and returns the replacement; persisting the result and removing $this
     * is infrastructure work left to the caller (see AnimeTypeMigrator).
     */
    public function migrate(AnimeType $targetType): self
    {
        if ($this->getType() === $targetType) {
            throw new InvalidAnimeTypeMigrationException('Type migration to the same type is not allowed');
        }

        if ($this->getProductionStatus() !== ProductionStatus::Announced) {
            throw new InvalidAnimeTypeMigrationException('Type migration is only allowed while production status is announced');
        }

        $targetClass = $targetType->entityClass();
        $target = new $targetClass();

        $target->setTitle($this->title)
            ->setDatePremiere($this->datePremiere)
            ->setDateEnd($this->dateEnd)
            ->setDurationMinutes($this->durationMinutes)
            ->setNotes($this->notes)
            ->setUserRating($this->userRating)
            ->setCover($this->cover)
            ->setStorage($this->storage)
            ->setStoragePath($this->storagePath)
            ->setDemographic($this->demographic)
            ->setCountries($this->countries)
            ->setWatchStatus($this->watchStatus);

        // No public setter exists for these — they are sync bookkeeping, not something a caller
        // should ever set directly. Carried over by direct property assignment (legal here: both
        // are private to Anime, and migrate() is itself a method of Anime) so the reconciliation
        // snapshot's timestamp basis survives a type migration instead of resetting to null,
        // which would otherwise make every participant look "changed" on the next sync (issue
        // #365, "camp #13").
        $target->watchProgressUpdatedAt = $this->watchProgressUpdatedAt;
        $target->watchProgressRejectedAt = $this->watchProgressRejectedAt;

        foreach ($this->getGenreCodes() as $code) {
            $target->addGenre($code);
        }

        foreach ($this->getThemeCodes() as $code) {
            $target->addTheme($code);
        }

        foreach ($this->getStudios() as $studio) {
            $target->addStudio($studio);
        }

        foreach ($this->getLabels() as $label) {
            $target->addLabel($label);
        }

        foreach ($this->getNames() as $name) {
            $target->addName($name->name, $name->locale, $name->role);
        }

        foreach ($this->getImages() as $image) {
            $target->addImage($image->source);
        }

        foreach ($this->getSources() as $link) {
            $target->addSource($link->url);
        }

        // External ids live in their own table (issue #297), so a type migration must carry
        // them over explicitly or every synced plugin link would be silently orphaned by the
        // class swap.
        foreach ($this->externalIds as $entry) {
            $target->rememberExternalId(new PluginId($entry->pluginId), $entry->externalId);
        }

        foreach ($this->getDescriptions() as $description) {
            $target->setDescription($description->locale, $description->description);
        }

        if ($this instanceof SeriesAnime && $target instanceof SeriesAnime) {
            $target->setEpisodesCount($this->getEpisodesCount());
            $target->setWatchedEpisodes($this->getWatchedEpisodes());
        }

        return $target;
    }

    /** @return list<string>|null */
    public function getCountries(): ?array
    {
        return $this->countries;
    }

    /** @param list<string>|null $countries */
    public function setCountries(?array $countries): self
    {
        if ($countries !== null) {
            foreach ($countries as $code) {
                if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
                    throw new InvalidCountryCodeException(\sprintf('country code "%s" must be two uppercase ASCII letters', $code));
                }
            }
        }

        $this->countries = $countries;

        return $this;
    }

    public function getCover(): ?string
    {
        return $this->cover;
    }

    public function setCover(?string $cover): self
    {
        $this->cover = $cover;

        return $this;
    }

    public function getStorage(): ?Storage
    {
        return $this->storage;
    }

    public function setStorage(?Storage $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function setStoragePath(?string $storagePath): self
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function getDemographic(): ?Demographic
    {
        return $this->demographic;
    }

    public function setDemographic(?Demographic $demographic): self
    {
        $this->demographic = $demographic;

        return $this;
    }

    /**
     * Resolves and caches the external id this plugin uses for the anime, e.g. the
     * Shikimori id parsed from a shikimori.one source URL.
     *
     * Cached as an AnimeExternalId row (issue #297; previously metadata['external_id']
     * [$pluginId], an unindexed JSON blob) — a separate table from a plugin's own filler data,
     * which now lives in the `anime_plugin_data` table too (issue #299, see
     * {@see \App\Service\Plugin\PluginDataStore}): a future overwrite of that plugin's raw
     * filler data must not accidentally clobber an already-resolved id.
     */
    public function getExternalId(PluginId $pluginId, ExternalIdResolutionInterface $plugin): ?string
    {
        $cached = $this->getCachedExternalId($pluginId);
        if ($cached !== null) {
            return $cached;
        }

        $urls = array_map(static fn (AnimeSource $source): string => $source->url, $this->getSources()->toArray());
        $id = $plugin->resolveExternalId($urls);

        if ($id !== null) {
            $this->rememberExternalId($pluginId, $id);
        }

        return $id;
    }

    /**
     * Peeks the AnimeExternalId row already cached for $pluginId, if any, without ever
     * calling out to a plugin to resolve one — the read-only half of getExternalId(), used
     * where a caller only wants to check what's already known (e.g. BackfillExternalIdMessageHandler
     * skipping a row that's already resolved).
     */
    public function getCachedExternalId(PluginId $pluginId): ?string
    {
        foreach ($this->externalIds as $entry) {
            if ($entry->pluginId === (string) $pluginId) {
                return $entry->externalId;
            }
        }

        return null;
    }

    /**
     * @return list<PluginId> every plugin this anime already has a cached external id for
     */
    public function getExternalIdPluginIds(): array
    {
        return array_values(array_map(
            static fn (AnimeExternalId $entry): PluginId => new PluginId($entry->pluginId),
            $this->externalIds->toArray(),
        ));
    }

    /**
     * Caches an external id obtained without a resolveExternalId() round trip — e.g. from
     * FillerInterface::find() during bulk fill-in (issue #227), where the id comes back
     * directly from the plugin's search result instead of being parsed from a source URL.
     * Writes the same AnimeExternalId slot getExternalId() reads/writes, so a later
     * getExternalId() call for this plugin returns the cached value without re-resolving it.
     */
    public function rememberExternalId(PluginId $pluginId, string $externalId): self
    {
        foreach ($this->externalIds as $entry) {
            if ($entry->pluginId === (string) $pluginId) {
                if ($entry->externalId === $externalId) {
                    return $this;
                }

                $this->externalIds->removeElement($entry);
                break;
            }
        }

        $this->externalIds->add(new AnimeExternalId($this, $pluginId, $externalId));

        return $this;
    }

    /**
     * Attaches an AnimeExternalId row a caller already wrote to the database itself, bypassing
     * the ORM, and already told Doctrine's UnitOfWork is managed (issue #839 —
     * {@see \App\Service\Plugin\Filler\BulkFillerService} inserts via DBAL with `ON CONFLICT
     * DO NOTHING` so the anime_external_id UNIQUE constraint never has to close the
     * EntityManager to be enforced). Unlike rememberExternalId(), this never constructs a new
     * row of its own — doing so here would add a second, *unmanaged* AnimeExternalId instance
     * to the collection, which cascade persist would then schedule as a duplicate INSERT on the
     * very next flush().
     */
    public function attachPersistedExternalId(AnimeExternalId $entry): self
    {
        foreach ($this->externalIds as $existing) {
            if ($existing->pluginId === $entry->pluginId) {
                $this->externalIds->removeElement($existing);
                break;
            }
        }

        $this->externalIds->add($entry);

        return $this;
    }

    /**
     * Upserts the AnimeDescription row for $locale, the value getSummary() reads. An
     * existing row for the same locale is mutated in place (UPDATE), not replaced via
     * remove+add: the table has a UNIQUE(anime_id, locale) constraint, and Doctrine's
     * UnitOfWork issues all INSERTs before any DELETE on flush, so remove+add on an
     * already-persisted anime (e.g. PluginAnimeDataMerger::applyDescriptions() updating
     * an existing description) would violate that constraint before the old row is gone.
     */
    public function setDescription(string $locale, string $text): self
    {
        foreach ($this->descriptions as $description) {
            if ($description->locale === $locale) {
                $description->description = $text;

                return $this;
            }
        }

        $this->descriptions->add(new AnimeDescription($this, $locale, $text));

        return $this;
    }

    /** @return Collection<int, AnimeDescription> */
    public function getDescriptions(): Collection
    {
        return $this->descriptions;
    }

    public function getDateAdd(): \DateTimeImmutable
    {
        return $this->dateAdd;
    }

    public function getDateUpdate(): \DateTimeImmutable
    {
        return $this->dateUpdate;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->dateUpdate = new \DateTimeImmutable();
    }

    /** @return Collection<int, AnimeGenre> */
    public function getGenres(): Collection
    {
        return $this->genres;
    }

    /** @return list<GenreCode> */
    public function getGenreCodes(): array
    {
        return array_values(array_map(
            static fn (AnimeGenre $genre): GenreCode => $genre->code,
            $this->genres->toArray(),
        ));
    }

    public function addGenre(GenreCode $code): self
    {
        if (\in_array($code, $this->getGenreCodes(), true)) {
            return $this;
        }
        $this->genres->add(new AnimeGenre($this, $code));

        return $this;
    }

    public function removeGenre(GenreCode $code): self
    {
        foreach ($this->genres as $genre) {
            if ($genre->code === $code) {
                $this->genres->removeElement($genre);
                break;
            }
        }

        return $this;
    }

    /** @return Collection<int, AnimeTheme> */
    public function getThemes(): Collection
    {
        return $this->themes;
    }

    /** @return list<ThemeCode> */
    public function getThemeCodes(): array
    {
        return array_values(array_map(
            static fn (AnimeTheme $theme): ThemeCode => $theme->code,
            $this->themes->toArray(),
        ));
    }

    public function addTheme(ThemeCode $code): self
    {
        if (\in_array($code, $this->getThemeCodes(), true)) {
            return $this;
        }
        $this->themes->add(new AnimeTheme($this, $code));

        return $this;
    }

    public function removeTheme(ThemeCode $code): self
    {
        foreach ($this->themes as $theme) {
            if ($theme->code === $code) {
                $this->themes->removeElement($theme);
                break;
            }
        }

        return $this;
    }

    /** @return Collection<int, Studio> */
    public function getStudios(): Collection
    {
        return $this->studios;
    }

    public function addStudio(Studio $studio): self
    {
        if (!$this->studios->contains($studio)) {
            $this->studios->add($studio);
            $studio->addAnime($this);
        }

        return $this;
    }

    public function removeStudio(Studio $studio): self
    {
        if ($this->studios->removeElement($studio)) {
            $studio->removeAnime($this);
        }

        return $this;
    }

    /** @return Collection<int, Label> */
    public function getLabels(): Collection
    {
        return $this->labels;
    }

    public function addLabel(Label $label): self
    {
        if (!$this->labels->contains($label)) {
            $this->labels->add($label);
            $label->addAnime($this);
        }

        return $this;
    }

    public function removeLabel(Label $label): self
    {
        if ($this->labels->removeElement($label)) {
            $label->removeAnime($this);
        }

        return $this;
    }

    /** @return Collection<int, AnimeName> */
    public function getNames(): Collection
    {
        return $this->names;
    }

    public function addName(string $name, ?string $locale, AnimeNameRole $role): self
    {
        $this->names->add(new AnimeName($this, $name, $locale, $role));

        return $this;
    }

    public function removeName(AnimeName $name): self
    {
        $this->names->removeElement($name);

        return $this;
    }

    /** @return Collection<int, AnimeImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(string $source): self
    {
        $this->images->add(new AnimeImage($this, $source));

        return $this;
    }

    public function removeImage(AnimeImage $image): self
    {
        $this->images->removeElement($image);

        return $this;
    }

    /** @return Collection<int, AnimeSource> */
    public function getSources(): Collection
    {
        return $this->sources;
    }

    public function addSource(string $url): self
    {
        $this->sources->add(new AnimeSource($this, $url));

        return $this;
    }

    public function removeSource(AnimeSource $source): self
    {
        $this->sources->removeElement($source);

        return $this;
    }

    /**
     * Computed from datePremiere/dateEnd, not a persisted column. Compared against the
     * current moment (not the start of today) so the status is accurate right after a
     * release happens, not only from the next day. Order of checks matters: dateEnd in
     * the past wins over an ongoing premiere; datePremiere == now counts as ongoing.
     */
    public function getProductionStatus(): ProductionStatus
    {
        $now = new \DateTimeImmutable();

        if ($this->dateEnd !== null && $this->dateEnd <= $now) {
            return ProductionStatus::Released;
        }

        if ($this->datePremiere !== null && $this->datePremiere <= $now
            && ($this->dateEnd === null || $this->dateEnd > $now)) {
            return ProductionStatus::Ongoing;
        }

        return ProductionStatus::Announced;
    }

    /**
     * Resolves the $descriptions row (issue #298) for the given UI locale: preferred locale
     * -> en -> any available -> empty string. Triggers the one lazy query loading this
     * anime's own description rows on first access (see $descriptions docblock) — called
     * only from AnimeViewFactory::serialize(), i.e. only when rendering the anime detail
     * page, never from the catalog list.
     */
    public function getSummary(string $locale): string
    {
        if ($this->descriptions->isEmpty()) {
            return '';
        }

        foreach ($this->descriptions as $description) {
            if ($description->locale === $locale) {
                return $description->description;
            }
        }

        foreach ($this->descriptions as $description) {
            if ($description->locale === self::FALLBACK_LOCALE) {
                return $description->description;
            }
        }

        foreach ($this->descriptions as $description) {
            return $description->description;
        }

        return '';
    }
}
