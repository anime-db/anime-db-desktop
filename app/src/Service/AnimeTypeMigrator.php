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

namespace App\Service;

use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\ProductionStatus;
use App\Entity\Exception\InvalidAnimeTypeMigrationException;
use App\Entity\MovieAnime;
use App\Entity\MusicAnime;
use App\Entity\OnaAnime;
use App\Entity\OvaAnime;
use App\Entity\SpecialAnime;
use App\Entity\TvAnime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recreates an Anime under a different concrete class, the only mechanism available
 * for changing type across the Movie/Series boundary: that is the only boundary where
 * the persisted field set actually differs (episodesCount/watchedEpisodes exist only
 * on SeriesAnime), so Doctrine's single-table discriminator alone cannot express it.
 *
 * Switching between SeriesAnime leaves (Tv/Ova/Ona/Special/Music) is a same-row
 * discriminator change with no field-set difference and is intentionally out of scope
 * here, see issue #63.
 */
final class AnimeTypeMigrator
{
    /** @var array<value-of<AnimeType>, class-string<Anime>> */
    private const CLASS_BY_TYPE = [
        AnimeType::Movie->value => MovieAnime::class,
        AnimeType::Tv->value => TvAnime::class,
        AnimeType::Ova->value => OvaAnime::class,
        AnimeType::Ona->value => OnaAnime::class,
        AnimeType::Special->value => SpecialAnime::class,
        AnimeType::Music->value => MusicAnime::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param AnimeType $targetType must be on the opposite side of the Movie/Series boundary from $source
     */
    public function migrate(Anime $source, AnimeType $targetType): Anime
    {
        $this->assertMigrationAllowed($source, $targetType);

        $targetClass = self::CLASS_BY_TYPE[$targetType->value];
        $target = new $targetClass();

        $target->setTitle($source->getTitle())
            ->setDatePremiere($source->getDatePremiere())
            ->setDateEnd($source->getDateEnd())
            ->setDurationMinutes($source->getDurationMinutes())
            ->setNotes($source->getNotes())
            ->setUserRating($source->getUserRating())
            ->copyMetadataFrom($source)
            ->setCover($source->getCover())
            ->setStorage($source->getStorage())
            ->setCountries($source->getCountries())
            ->setWatchStatus($source->getWatchStatus());

        foreach ($source->getGenreCodes() as $code) {
            $target->addGenre($code);
        }

        foreach ($source->getStudios() as $studio) {
            $target->addStudio($studio);
        }

        foreach ($source->getLabels() as $label) {
            $target->addLabel($label);
        }

        foreach ($source->getNames() as $name) {
            $target->addName($name->name, $name->type);
        }

        foreach ($source->getImages() as $image) {
            $target->addImage($image->source);
        }

        foreach ($source->getSources() as $link) {
            $target->addSource($link->url);
        }

        // Persist the copy (and its cascaded genres/names/images/sources) before removing
        // the source, so the ON DELETE CASCADE on anime_id never fires against data we
        // still need: watchedEpisodes/episodesCount are not transferred here on purpose,
        // they are guaranteed empty on the Series side while production status is announced.
        $this->entityManager->persist($target);
        $this->entityManager->remove($source);
        $this->entityManager->flush();

        return $target;
    }

    private function assertMigrationAllowed(Anime $source, AnimeType $targetType): void
    {
        if (($source instanceof MovieAnime) === (AnimeType::Movie === $targetType)) {
            throw new InvalidAnimeTypeMigrationException('Type migration is only allowed between the Movie and Series branches');
        }

        if (ProductionStatus::Announced !== $source->getProductionStatus()) {
            throw new InvalidAnimeTypeMigrationException('Type migration is only allowed while production status is announced');
        }
    }
}
