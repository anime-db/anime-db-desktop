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

namespace App\Entity;

use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ProductionStatus;
use App\Entity\Enum\WatchStatus;
use App\Entity\Exception\InvalidAnimeTypeMigrationException;
use App\Entity\Exception\InvalidCountryCodeException;
use App\Entity\Exception\InvalidDateRangeException;
use App\Entity\Exception\InvalidDurationException;
use App\Entity\Exception\InvalidNameException;
use App\Entity\Exception\InvalidWatchStatusException;
use App\Entity\ValueObject\PluginId;
use App\Entity\ValueObject\Rating;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
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
abstract class Anime
{
    /**
     * Fallback locale for getSummary() when the requested UI locale has no description.
     */
    private const FALLBACK_LOCALE = 'en';

    /** @var array<value-of<AnimeType>, class-string<self>> */
    private const CLASS_BY_TYPE = [
        AnimeType::Movie->value => MovieAnime::class,
        AnimeType::Tv->value => TvAnime::class,
        AnimeType::Ova->value => OvaAnime::class,
        AnimeType::Ona->value => OnaAnime::class,
        AnimeType::Special->value => SpecialAnime::class,
        AnimeType::Music->value => MusicAnime::class,
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Primary display title (fallback while the anime_name records are not filled in yet).
     */
    #[ORM\Column(length: 256)]
    private string $title;

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
     * Relative path to the cover file on disk, resolved via $storage + app-media:// (separate task).
     */
    #[ORM\Column(length: 256, nullable: true)]
    private ?string $cover = null;

    #[ORM\ManyToOne(targetEntity: Storage::class)]
    #[ORM\JoinColumn(name: 'storage_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Storage $storage = null;

    /** @var array<string, mixed>|null raw plugin data, including descriptions{} used by getSummary() */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'unix_timestamp')]
    private \DateTimeImmutable $dateAdd;

    #[ORM\Column(type: 'unix_timestamp')]
    private \DateTimeImmutable $dateUpdate;

    /** @var Collection<int, AnimeGenre> */
    #[ORM\OneToMany(mappedBy: 'anime', targetEntity: AnimeGenre::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $genres;

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

    public function __construct()
    {
        $this->genres = new ArrayCollection();
        $this->studios = new ArrayCollection();
        $this->labels = new ArrayCollection();
        $this->names = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->sources = new ArrayCollection();
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
        if ('' === $title) {
            throw new InvalidNameException('title must not be empty');
        }

        $this->title = $title;

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
        if (null !== $datePremiere && null !== $dateEnd && $dateEnd < $datePremiere) {
            throw new InvalidDateRangeException('date_end must not be earlier than date_premiere');
        }
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(?int $durationMinutes): self
    {
        if (null !== $durationMinutes && $durationMinutes <= 0) {
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
     * changing type across the Movie/Series boundary: that is the only boundary where the
     * persisted field set actually differs (episodesCount/watchedEpisodes exist only on
     * SeriesAnime), so Doctrine's single-table discriminator alone cannot express it.
     *
     * Switching between SeriesAnime leaves (Tv/Ova/Ona/Special/Music) is a same-row
     * discriminator change with no field-set difference and is intentionally out of scope
     * here, see issue #63.
     *
     * Only builds and returns the replacement; persisting the result and removing $this
     * is infrastructure work left to the caller (see AnimeTypeMigrator).
     */
    public function migrate(AnimeType $targetType): self
    {
        if (($this instanceof MovieAnime) === (AnimeType::Movie === $targetType)) {
            throw new InvalidAnimeTypeMigrationException('Type migration is only allowed between the Movie and Series branches');
        }

        if (ProductionStatus::Announced !== $this->getProductionStatus()) {
            throw new InvalidAnimeTypeMigrationException('Type migration is only allowed while production status is announced');
        }

        $targetClass = self::CLASS_BY_TYPE[$targetType->value];
        $target = new $targetClass();

        $target->setTitle($this->title)
            ->setDatePremiere($this->datePremiere)
            ->setDateEnd($this->dateEnd)
            ->setDurationMinutes($this->durationMinutes)
            ->setNotes($this->notes)
            ->setUserRating($this->userRating)
            ->setCover($this->cover)
            ->setStorage($this->storage)
            ->setCountries($this->countries)
            ->setWatchStatus($this->watchStatus);
        $target->assignMetadataFrom($this);

        foreach ($this->getGenreCodes() as $code) {
            $target->addGenre($code);
        }

        foreach ($this->getStudios() as $studio) {
            $target->addStudio($studio);
        }

        foreach ($this->getLabels() as $label) {
            $target->addLabel($label);
        }

        foreach ($this->getNames() as $name) {
            $target->addName($name->name, $name->type);
        }

        foreach ($this->getImages() as $image) {
            $target->addImage($image->source);
        }

        foreach ($this->getSources() as $link) {
            $target->addSource($link->url);
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
        if (null !== $countries) {
            foreach ($countries as $code) {
                if (1 !== preg_match('/^[A-Z]{2}$/', $code)) {
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

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * Overwrites the whole metadata blob at once, unlike putPluginData()/setDescription()
     * which merge into a namespaced slice. Private (not just protected) and used only by
     * migrate() above: no caller, including subclasses, has a reason to clobber another
     * plugin's data or descriptions{} wholesale outside of that use case.
     */
    private function assignMetadataFrom(self $source): void
    {
        $this->metadata = $source->metadata;
    }

    /**
     * Merges $data into this plugin's own namespaced slice of metadata, leaving the data
     * of every other plugin (and descriptions{}) untouched.
     *
     * @param array<string, mixed> $data
     */
    public function putPluginData(PluginId $pluginId, array $data): self
    {
        $metadata = $this->metadata ?? [];
        $existing = $metadata['plugins'][(string) $pluginId] ?? [];
        $metadata['plugins'][(string) $pluginId] = [...(\is_array($existing) ? $existing : []), ...$data];
        $this->metadata = $metadata;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPluginData(PluginId $pluginId): array
    {
        $data = $this->metadata['plugins'][(string) $pluginId] ?? [];

        return \is_array($data) ? $data : [];
    }

    /**
     * Writes metadata.descriptions[$locale], the value getSummary() reads.
     */
    public function setDescription(string $locale, string $text): self
    {
        $metadata = $this->metadata ?? [];
        $metadata['descriptions'][$locale] = $text;
        $this->metadata = $metadata;

        return $this;
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

    public function addName(string $name, AnimeNameType $type): self
    {
        $this->names->add(new AnimeName($this, $name, $type));

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

        if (null !== $this->dateEnd && $this->dateEnd <= $now) {
            return ProductionStatus::Released;
        }

        if (null !== $this->datePremiere && $this->datePremiere <= $now
            && (null === $this->dateEnd || $this->dateEnd > $now)) {
            return ProductionStatus::Ongoing;
        }

        return ProductionStatus::Announced;
    }

    /**
     * Resolves metadata.descriptions{} (e.g. {"ru": "...", "en": "..."}) for the given UI locale:
     * preferred locale -> en -> any available -> empty string.
     */
    public function getSummary(string $locale): string
    {
        $descriptions = $this->metadata['descriptions'] ?? null;
        if (!\is_array($descriptions) || [] === $descriptions) {
            return '';
        }

        if (isset($descriptions[$locale]) && \is_string($descriptions[$locale])) {
            return $descriptions[$locale];
        }

        if (isset($descriptions[self::FALLBACK_LOCALE]) && \is_string($descriptions[self::FALLBACK_LOCALE])) {
            return $descriptions[self::FALLBACK_LOCALE];
        }

        foreach ($descriptions as $value) {
            if (\is_string($value)) {
                return $value;
            }
        }

        return '';
    }
}
