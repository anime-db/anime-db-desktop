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
use AnimeDb\PluginContracts\Model\AnimeName as ContractsAnimeName;
use AnimeDb\PluginContracts\Model\Demographic as ContractsDemographic;
use AnimeDb\PluginContracts\Model\GenreCode as ContractsGenreCode;
use AnimeDb\PluginContracts\Model\ThemeCode as ContractsThemeCode;
use App\Entity\Anime;
use App\Entity\AnimeImage;
use App\Entity\AnimeName;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Exception\InvalidDateRangeException;
use App\Entity\LocaleNormalizer;
use App\Entity\NameNormalizer;
use App\Entity\SeriesAnime;
use App\Entity\Studio;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies a plugin-supplied {@see PluginAnimeData} onto an {@see Anime}, one field at a time,
 * enforcing the merge-vs-overwrite rule the issue requires to live on the host's schema rather
 * than in the plugin (issue #231): collection fields (alternative names, descriptions, genres,
 * themes, studios, countries, images) are unioned via Anime's own add*()/set-with-existing
 * methods, which already de-duplicate or merge by key — nothing that already exists is lost.
 * Scalar fields (title, date_premiere, date_end, duration_minutes, demographic,
 * episodes_count, cover) are overwritten outright — except date_premiere/date_end, which are
 * applied together through {@see Anime::setDatePremiereAndEnd()} rather than one field at a
 * time (issue #860): a pair that would violate end >= premiere is rejected as a whole (a
 * warning is logged, neither date changes) instead of letting the entity's own invariant
 * exception escape apply() with one date already written and the other not.
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
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string[] $fields PluginAnimeData property names to apply — already filtered by
     *                         the caller to what the plugin claims to support
     *                         ({@see \AnimeDb\PluginContracts\Filler\FillerInterface::getFillableFields()})
     *                         and, for the point fill-in scenario, to the single field the
     *                         user asked for
     *
     * @return MergeResult see that class's own docblock for why a rejected date_premiere/date_end
     *                     pair is reported separately from the 'cover'/'images' download failures
     *                     $unapplied carries
     */
    public function apply(Anime $anime, PluginAnimeData $data, array $fields): MergeResult
    {
        // Handled once, together, regardless of where 'datePremiere'/'dateEnd' sit in $fields —
        // see applyDatePremiereAndEnd() for why the pair cannot be applied field-by-field below.
        $dateFields = array_values(array_intersect(['datePremiere', 'dateEnd'], $fields));
        $dateRangeRejected = $dateFields !== [] && !$this->applyDatePremiereAndEnd($anime, $data, $dateFields);

        $unapplied = [];
        foreach ($fields as $field) {
            // Every arm here is void except applyCover()/applyImages(), which are the only ones
            // that can ever report a real rejection - a void arm evaluates to null, so the
            // `=== false` check below can never mistake "nothing to check" for "rejected".
            $applied = match ($field) {
                'title' => $anime->setTitle($data->title),
                'alternativeNames' => $this->applyAlternativeNames($anime, $data->alternativeNames ?? []),
                'descriptions' => $this->applyDescriptions($anime, $data->descriptions ?? []),
                'genres' => $this->applyGenres($anime, $data->genres ?? []),
                'themes' => $this->applyThemes($anime, $data->themes ?? []),
                'demographic' => $this->applyDemographic($anime, $data->demographic),
                'studios' => $this->applyStudios($anime, $data->studios ?? []),
                'datePremiere', 'dateEnd' => null, // applied as a pair above
                'durationMinutes' => $data->durationMinutes !== null ? $anime->setDurationMinutes($data->durationMinutes) : null,
                'episodesCount' => $this->applyEpisodesCount($anime, $data->episodesCount),
                'countries' => $this->applyCountries($anime, $data->countries ?? []),
                'cover' => $this->applyCover($anime, $data->cover),
                'images' => $this->applyImages($anime, $data->images ?? []),
                default => null, // type: not applicable to an already-constructed entity, see class docblock
            };

            if ($applied === false) {
                $unapplied[] = $field;
            }
        }

        return new MergeResult($unapplied, $dateRangeRejected);
    }

    /**
     * Builds the (datePremiere, dateEnd) pair apply() would end up with and applies it through
     * {@see Anime::setDatePremiereAndEnd()} in one call, so the entity checks end >= premiere
     * once for the pair that would actually result — not once per field, which could succeed on
     * the first date and throw on the second, in whichever order $fields happens to list them
     * (issue #860). For each of the two dates: $data's value when that field is present in
     * $fields and non-null, otherwise $anime's already-stored value (unchanged by this call).
     *
     * A pair that violates the invariant is rejected as a whole — neither date is changed — and
     * logged as a warning. The rejection is reported back to apply() through this method's return
     * value rather than added to $unapplied: FieldFillerService and BulkFillerService turn a
     * non-empty $unapplied into FillResult::ImageRejected, which would surface a rejected date
     * pair to the user as a rejected image instead of the date conflict it actually is.
     *
     * @param list<string> $fields the subset of the caller's $fields that is 'datePremiere'
     *                             and/or 'dateEnd' — never empty, apply() only calls this when
     *                             at least one of the two is present
     *
     * @return bool false when the pair was rejected (neither date changed), true otherwise
     */
    private function applyDatePremiereAndEnd(Anime $anime, PluginAnimeData $data, array $fields): bool
    {
        $datePremiere = \in_array('datePremiere', $fields, true) && $data->datePremiere !== null
            ? $data->datePremiere
            : $anime->getDatePremiere();
        $dateEnd = \in_array('dateEnd', $fields, true) && $data->dateEnd !== null
            ? $data->dateEnd
            : $anime->getDateEnd();

        try {
            $anime->setDatePremiereAndEnd($datePremiere, $dateEnd);

            return true;
        } catch (InvalidDateRangeException) {
            $this->logger->warning('Rejected a date_premiere/date_end pair that would violate date_end >= date_premiere; leaving both dates unchanged.', [
                'animeId' => $anime->id,
                'sourceDatePremiere' => $data->datePremiere,
                'sourceDateEnd' => $data->dateEnd,
                'storedDatePremiere' => $anime->getDatePremiere(),
                'storedDateEnd' => $anime->getDateEnd(),
            ]);

            return false;
        }
    }

    /**
     * Dedup key is (normalizedName, locale) — issue #724: two names with the same text but
     * different locales coexist (e.g. an official title with one per language). A catalog row
     * that already has a locale is never touched; one whose locale is still null yields to an
     * incoming pair carrying a non-null locale for the same text, on the theory that "unknown
     * language" is strictly less specific than "known language" and the filler is the one place
     * that can self-heal a locale nobody had typed yet.
     *
     * @param ContractsAnimeName[] $names
     */
    private function applyAlternativeNames(Anime $anime, array $names): void
    {
        /** @var array<string, array<string, AnimeName>> $existingByKey normalizedName => (locale ?? '') => AnimeName */
        $existingByKey = [];
        foreach ($anime->getNames() as $existingName) {
            $existingByKey[$existingName->normalizedName][$existingName->locale ?? ''] ??= $existingName;
        }

        /** @var array<string, true> $seenInThisBatch */
        $seenInThisBatch = [];

        foreach ($names as $incoming) {
            $locale = LocaleNormalizer::normalize($incoming->locale);
            $normalizedName = NameNormalizer::normalize($incoming->name);
            $key = $locale ?? '';
            $batchKey = $normalizedName."\0".$key;

            if (isset($seenInThisBatch[$batchKey])) {
                continue;
            }
            $seenInThisBatch[$batchKey] = true;

            if (isset($existingByKey[$normalizedName][$key])) {
                continue;
            }

            if ($locale !== null && isset($existingByKey[$normalizedName][''])) {
                $anime->removeName($existingByKey[$normalizedName]['']);
                unset($existingByKey[$normalizedName]['']);
            }

            $anime->addName($incoming->name, $locale, AnimeNameRole::from($incoming->role->value));
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
     * see BulkFillerService). That skip is not a rejection (nothing was attempted, so it never
     * belongs in apply()'s unapplied-fields list), unlike a download/normalize failure below.
     *
     * @return bool false when $url was given but the download or WebP normalization failed —
     *              apply() reports that back to the caller so it can tell "the plugin found
     *              nothing" apart from "the plugin found an image the host could not save"
     */
    private function applyCover(Anime $anime, ?string $url): bool
    {
        if ($url === null || $anime->id === null) {
            return true;
        }

        $filename = $this->mediaDownloader->download($anime->id, $url);
        if ($filename === null) {
            return false;
        }

        $anime->setCover($filename);

        return true;
    }

    /**
     * @param string[] $urls
     *
     * @return bool false only when $urls was non-empty and every single URL failed to download —
     *              one accepted URL among several rejected ones is still success (see
     *              applyCover()'s docblock for why the same call reports a failure at all);
     *              a URL that downloads to a filename the gallery already has is not a failure
     *              either, it is the union-by-filename dedup this class enforces
     *
     * @see applyCover() for why a not-yet-persisted Anime is skipped
     */
    private function applyImages(Anime $anime, array $urls): bool
    {
        if ($anime->id === null || $urls === []) {
            return true;
        }

        $existing = array_map(static fn (AnimeImage $image): string => $image->source, $anime->getImages()->toArray());
        $downloadedAny = false;

        foreach ($urls as $url) {
            $filename = $this->mediaDownloader->download($anime->id, $url);
            if ($filename === null) {
                continue;
            }

            $downloadedAny = true;
            if (!\in_array($filename, $existing, true)) {
                $anime->addImage($filename);
                $existing[] = $filename;
            }
        }

        return $downloadedAny;
    }
}
