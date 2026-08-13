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

use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Model\Demographic as ContractsDemographic;
use AnimeDb\PluginContracts\Model\GenreCode as ContractsGenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode as ContractsThemeCode;
use App\Entity\Anime;
use App\Entity\AnimeImage;
use App\Entity\AnimeName;
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\NameNormalizer;
use App\Entity\SeriesAnime;
use App\Entity\Studio;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies a plugin-supplied {@see PluginAnimeData} onto an {@see Anime}, one field at a time,
 * enforcing the merge-vs-overwrite rule the issue requires to live on the host's schema rather
 * than in the plugin (issue #231): collection fields (alternative names, descriptions, genres,
 * themes, studios, countries, images) are unioned via Anime's own add*()/set-with-existing
 * methods, which already de-duplicate or merge by key — nothing that already exists is lost.
 * Scalar fields (title, date_premiere, date_end, duration_minutes, demographic,
 * episodes_count, cover) are overwritten outright.
 *
 * `type` is deliberately not handled here: Doctrine's single-table discriminator is fixed per
 * row, so it can only be chosen at entity-construction time (BulkFillerService::instantiate())
 * or through Anime::migrate() — neither is a "merge one field onto an already-persisted row"
 * operation this class performs.
 */
final class PluginAnimeDataMerger
{
    /**
     * Keyed by studio name, populated across every apply() call for the lifetime of this
     * (Symfony-shared) service instance. Doctrine's repository query cannot see a Studio
     * persisted earlier in the same unflushed unit of work, so without this cache two new
     * Anime records with the same new studio, filled in the same scan before the caller's
     * single flush(), would each create their own duplicate Studio row. Same pattern as
     * {@see \App\Service\Install\SampleAnimeSeeder::findOrCreateStudio()}.
     *
     * @var array<string, Studio>
     */
    private array $studioCache = [];

    public function __construct(
        private readonly StudioRepository $studioRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PluginMediaDownloaderInterface $mediaDownloader,
    ) {
    }

    /**
     * @param string[] $fields PluginAnimeData property names to apply — already filtered by
     *                         the caller to what the plugin claims to support
     *                         ({@see \AnimeDb\PluginContracts\Filler\FillerInterface::getFillableFields()})
     *                         and, for the point fill-in scenario, to the single field the
     *                         user asked for
     */
    public function apply(Anime $anime, PluginAnimeData $data, array $fields): void
    {
        foreach ($fields as $field) {
            match ($field) {
                'title' => $anime->setTitle($data->title),
                'alternativeNames' => $this->applyAlternativeNames($anime, $data->alternativeNames ?? []),
                'descriptions' => $this->applyDescriptions($anime, $data->descriptions ?? []),
                'genres' => $this->applyGenres($anime, $data->genres ?? []),
                'themes' => $this->applyThemes($anime, $data->themes ?? []),
                'demographic' => $this->applyDemographic($anime, $data->demographic),
                'studios' => $this->applyStudios($anime, $data->studios ?? []),
                'datePremiere' => $data->datePremiere !== null ? $anime->setDatePremiere($data->datePremiere) : null,
                'dateEnd' => $data->dateEnd !== null ? $anime->setDateEnd($data->dateEnd) : null,
                'durationMinutes' => $data->durationMinutes !== null ? $anime->setDurationMinutes($data->durationMinutes) : null,
                'episodesCount' => $this->applyEpisodesCount($anime, $data->episodesCount),
                'countries' => $this->applyCountries($anime, $data->countries ?? []),
                'cover' => $this->applyCover($anime, $data->cover),
                'images' => $this->applyImages($anime, $data->images ?? []),
                default => null, // type: not applicable to an already-constructed entity, see class docblock
            };
        }
    }

    /** @param string[] $names */
    private function applyAlternativeNames(Anime $anime, array $names): void
    {
        $existing = array_map(static fn (AnimeName $name): string => $name->normalizedName, $anime->getNames()->toArray());

        foreach ($names as $name) {
            $normalized = NameNormalizer::normalize($name);
            if (!\in_array($normalized, $existing, true)) {
                $anime->addName($name, AnimeNameType::Synonym);
                $existing[] = $normalized;
            }
        }
    }

    /**
     * setDescription() already merges by locale key (metadata['descriptions'][$locale] = $text),
     * leaving other locales untouched — the union rule for this field is already the schema's
     * built-in behavior, nothing extra to do here.
     *
     * @param array<string, string> $descriptions locale => text
     */
    private function applyDescriptions(Anime $anime, array $descriptions): void
    {
        foreach ($descriptions as $locale => $text) {
            $anime->setDescription((string) $locale, $text);
        }
    }

    /** @param ContractsGenreCode[] $genres */
    private function applyGenres(Anime $anime, array $genres): void
    {
        foreach ($genres as $genre) {
            $anime->addGenre(GenreCode::from($genre->value));
        }
    }

    /** @param ContractsThemeCode[] $themes */
    private function applyThemes(Anime $anime, array $themes): void
    {
        foreach ($themes as $theme) {
            $anime->addTheme(ThemeCode::from($theme->value));
        }
    }

    private function applyDemographic(Anime $anime, ?ContractsDemographic $demographic): void
    {
        if ($demographic !== null) {
            $anime->setDemographic(Demographic::from($demographic->value));
        }
    }

    /** @param string[] $studios */
    private function applyStudios(Anime $anime, array $studios): void
    {
        foreach ($studios as $name) {
            $anime->addStudio($this->studioCache[$name] ??= $this->resolveStudio($name));
        }
    }

    private function resolveStudio(string $name): Studio
    {
        $studio = $this->studioRepository->findOneByName($name);
        if ($studio === null) {
            $studio = new Studio();
            $studio->rename($name);
            $this->entityManager->persist($studio);
        }

        return $studio;
    }

    private function applyEpisodesCount(Anime $anime, ?int $episodesCount): void
    {
        if ($episodesCount !== null && $anime instanceof SeriesAnime) {
            $anime->setEpisodesCount($episodesCount);
        }
    }

    /** @param string[] $countries ISO 3166-1 alpha-2 codes */
    private function applyCountries(Anime $anime, array $countries): void
    {
        if ($countries === []) {
            return;
        }

        $anime->setCountries(array_values(array_unique([...($anime->getCountries() ?? []), ...$countries])));
    }

    /**
     * Downloading needs %AppData%/media/{$anime->id}/ to exist as a path, which is only true
     * once the entity has a database id — silently skipped for a not-yet-persisted Anime (the
     * bulk fill-in scenario, which does not pass 'cover' in $fields for exactly this reason,
     * see BulkFillerService).
     */
    private function applyCover(Anime $anime, ?string $url): void
    {
        if ($url === null || $anime->id === null) {
            return;
        }

        $filename = $this->mediaDownloader->download($anime->id, $url);
        if ($filename !== null) {
            $anime->setCover($filename);
        }
    }

    /**
     * @param string[] $urls
     *
     * @see applyCover() for why a not-yet-persisted Anime is skipped
     */
    private function applyImages(Anime $anime, array $urls): void
    {
        if ($anime->id === null || $urls === []) {
            return;
        }

        $existing = array_map(static fn (AnimeImage $image): string => $image->source, $anime->getImages()->toArray());

        foreach ($urls as $url) {
            $filename = $this->mediaDownloader->download($anime->id, $url);
            if ($filename !== null && !\in_array($filename, $existing, true)) {
                $anime->addImage($filename);
                $existing[] = $filename;
            }
        }
    }
}
