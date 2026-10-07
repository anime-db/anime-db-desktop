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

namespace App\Service\Import\V1;

use App\Entity\Anime;
use App\Entity\Enum\AnimeNameRole;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\ProductionStatus;
use App\Entity\Enum\WatchStatus;
use App\Entity\SeriesAnime;
use App\Entity\ValueObject\Rating;

/**
 * Builds an aggregate out of a record of an AnimeDB v1 catalog (issue #951): the v1 → v2 mapping
 * lives here, in the import layer; the entity knows nothing about the foreign format and is
 * driven through its own public API, so its setters check the invariants.
 *
 * The factory has no repository: the resolver supplies the entity class, the status and the
 * reference rows (labels, studios, storage) already deduplicated.
 *
 * The order of the steps is fixed: dates, then the episode count, then the status.
 * setWatchStatus(Completed) asks getProductionStatus(), which reads the dates, and
 * SeriesAnime::setWatchStatus() copies the episode count into the watched episodes.
 *
 * @internal used by the v1 import only
 */
final class V1AnimeFactory
{
    public function __construct(private readonly V1AnimeResolverInterface $resolver)
    {
    }

    public function create(V1AnimeRecord $record): Anime
    {
        $resolver = $this->resolver;
        $class = $resolver->resolveType($record)->entityClass();
        $self = new $class();

        $self->setTitle($record->title);

        $premiere = $record->datePremiere;
        $end = $record->dateEnd;
        if ($premiere !== null && $end !== null && $end < $premiere) {
            $end = null;
        }
        // A film or an OVA has no end date of its own; a series without one may still be airing.
        if ($end === null && $premiere !== null && $self->getType() !== AnimeType::Tv) {
            $end = $premiere;
        }
        $self->setDatePremiereAndEnd($premiere, $end);

        if ($record->duration !== null && $record->duration > 0) {
            $self->setDurationMinutes($record->duration);
        }

        if ($self instanceof SeriesAnime && $record->episodesNumber !== null && $record->episodesNumber > 0) {
            $self->setEpisodesCount($record->episodesNumber);
        }

        $status = $resolver->resolveWatchStatus($record);
        if ($status === WatchStatus::Completed) {
            // Completed needs a released title; a series with an unknown end is still on air.
            // The import reports every record this demotes.
            $status = match ($self->getProductionStatus()) {
                ProductionStatus::Released => $status,
                ProductionStatus::Ongoing => WatchStatus::Watching,
                ProductionStatus::Announced => WatchStatus::Plan,
            };
        }
        $self->setWatchStatus($status);

        if ($record->rating !== null && $record->rating >= 1 && $record->rating <= 5) {
            $self->setUserRating(new Rating($record->rating));
        }

        $country = strtoupper(trim($record->country ?? ''));
        if (preg_match('/^[A-Z]{2}\z/', $country) === 1) {
            $self->setCountries([$country]);
        }

        $self->setNotes($resolver->resolveNotes($record));

        $summary = trim($record->summary ?? '');
        if ($summary !== '') {
            $self->setDescription('ru', $summary);
        }

        foreach ($resolver->resolveStudios($record) as $studio) {
            $self->addStudio($studio);
        }
        foreach ($resolver->resolveLabels($record) as $label) {
            $self->addLabel($label);
        }

        $genres = $resolver->resolveGenres($record);
        foreach ($genres->genres as $code) {
            $self->addGenre($code);
        }
        foreach ($genres->themes as $code) {
            $self->addTheme($code);
        }
        $self->setDemographic($genres->demographic);

        foreach ($resolver->resolveNames($record) as $name) {
            $self->addName($name->name, $name->locale, AnimeNameRole::Synonym);
        }
        foreach ($record->sources as $url) {
            $self->addSource($url);
        }

        $storage = $resolver->resolveStorage($record);
        if ($storage !== null) {
            $self->setStorage($storage);
            $path = trim($record->path ?? '');
            if ($path !== '') {
                $self->setStoragePath($path);
            }
        }

        // Last, so nothing above can stamp "now" over the history.
        if ($record->dateAdd !== null) {
            $updated = $record->dateUpdate;
            if ($updated !== null && $updated < $record->dateAdd) {
                $updated = null;
            }
            $self->restoreTimestamps($record->dateAdd, $updated);
        } elseif ($record->dateUpdate !== null) {
            $self->restoreTimestamps($self->getDateAdd() < $record->dateUpdate ? $self->getDateAdd() : $record->dateUpdate, $record->dateUpdate);
        }

        return $self;
    }
}
