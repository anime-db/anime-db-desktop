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

namespace App\Service\Install;

use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\SeriesAnime;
use App\Entity\Studio;
use App\Repository\LabelRepository;
use App\Repository\StudioRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seeds the catalog with 7 hand-picked sample anime (issue #181, step 7/7 of the install
 * wizard): called once from the onboarding banner's "Load demo data" button, never as a
 * console command or doctrine-fixtures-bundle fixture — this is opt-in production data,
 * not dev/test scaffolding. Not defensively guarded against a second call: the banner (and
 * therefore the button) only renders while the catalog is empty (see HomeController), so a
 * repeat call is not reachable through the UI.
 *
 * Every row gets the "Sample" label (find-or-create, same pattern as AnimeLabelController)
 * so the user can find/delete them later; there is deliberately no bulk-cleanup mechanism
 * (see issue #181 "Не входит в объём").
 */
class SampleAnimeSeeder
{
    private const LABEL_NAME = 'Sample';

    /**
     * @var list<array{title: string, type: AnimeType, episodesCount: ?int, durationMinutes: ?int, studio: string, genres: list<GenreCode>, cover: string}>
     */
    private const SAMPLES = [
        [
            'title' => 'Fullmetal Alchemist: Brotherhood',
            'type' => AnimeType::Tv,
            'episodesCount' => 64,
            'durationMinutes' => null,
            'studio' => 'Bones',
            'genres' => [GenreCode::Action, GenreCode::Adventure, GenreCode::Drama, GenreCode::Fantasy, GenreCode::Military, GenreCode::Shounen],
            'cover' => 'fullmetal-alchemist-brotherhood.webp',
        ],
        [
            'title' => 'Spirited Away',
            'type' => AnimeType::Movie,
            'episodesCount' => null,
            'durationMinutes' => 125,
            'studio' => 'Studio Ghibli',
            'genres' => [GenreCode::Adventure, GenreCode::Drama, GenreCode::Fantasy, GenreCode::Supernatural],
            'cover' => 'spirited-away.webp',
        ],
        [
            'title' => 'Gintama',
            'type' => AnimeType::Tv,
            'episodesCount' => 201,
            'durationMinutes' => null,
            'studio' => 'Sunrise',
            'genres' => [GenreCode::Action, GenreCode::Comedy, GenreCode::Historical, GenreCode::Parody, GenreCode::SciFi, GenreCode::Shounen],
            'cover' => 'gintama.webp',
        ],
        [
            'title' => 'Hellsing Ultimate',
            'type' => AnimeType::Ova,
            'episodesCount' => 10,
            'durationMinutes' => null,
            'studio' => 'Madhouse',
            'genres' => [GenreCode::Action, GenreCode::Horror, GenreCode::Supernatural, GenreCode::Vampire, GenreCode::Seinen],
            'cover' => 'hellsing-ultimate.webp',
        ],
        [
            'title' => 'Sousou no Frieren',
            'type' => AnimeType::Tv,
            'episodesCount' => 28,
            'durationMinutes' => null,
            'studio' => 'Madhouse',
            'genres' => [GenreCode::Adventure, GenreCode::Drama, GenreCode::Fantasy, GenreCode::Shounen],
            'cover' => 'sousou-no-frieren.webp',
        ],
        [
            'title' => 'One Punch Man',
            'type' => AnimeType::Tv,
            'episodesCount' => 12,
            'durationMinutes' => null,
            'studio' => 'Madhouse',
            'genres' => [GenreCode::Action, GenreCode::Comedy, GenreCode::SciFi, GenreCode::SuperPower, GenreCode::Seinen],
            'cover' => 'one-punch-man.webp',
        ],
        [
            'title' => 'Solo Leveling',
            'type' => AnimeType::Tv,
            'episodesCount' => 12,
            'durationMinutes' => null,
            'studio' => 'A-1 Pictures',
            'genres' => [GenreCode::Action, GenreCode::Adventure, GenreCode::Fantasy],
            'cover' => 'solo-leveling.webp',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StudioRepository $studios,
        private readonly LabelRepository $labels,
        private readonly string $mediaDir,
        private readonly string $sampleCoversDir,
    ) {
    }

    public function seed(): void
    {
        $label = $this->findOrCreateLabel();

        /** @var array<string, Studio> $studioCache local to this call — Doctrine's repository
         *  query cannot see a Studio persisted earlier in the same unflushed unit of work, so
         *  reusing the repository alone would create a duplicate row for every repeated studio
         *  name (e.g. Madhouse appears on 3 of the 7 titles below). */
        $studioCache = [];

        /** @var list<array{0: Anime, 1: string}> $pendingCovers */
        $pendingCovers = [];
        foreach (self::SAMPLES as $sample) {
            $studioCache[$sample['studio']] ??= $this->findOrCreateStudio($sample['studio']);

            $anime = $this->buildAnime($sample, $studioCache[$sample['studio']]);
            $anime->addLabel($label);
            $this->entityManager->persist($anime);

            $pendingCovers[] = [$anime, $sample['cover']];
        }

        // Cover files are copied after this flush so every Anime already has the id its
        // media directory (%AppData%/media/{id}/) is keyed on.
        $this->entityManager->flush();

        foreach ($pendingCovers as [$anime, $cover]) {
            $this->attachCover($anime, $cover);
        }

        $this->entityManager->flush();
    }

    /**
     * @param array{title: string, type: AnimeType, episodesCount: ?int, durationMinutes: ?int, studio: string, genres: list<GenreCode>, cover: string} $sample
     */
    private function buildAnime(array $sample, Studio $studio): Anime
    {
        $class = $sample['type']->entityClass();
        $anime = new $class();
        $anime->setTitle($sample['title'])
            ->setWatchStatus(WatchStatus::Plan)
            ->addStudio($studio);

        if (null !== $sample['durationMinutes']) {
            $anime->setDurationMinutes($sample['durationMinutes']);
        }

        if ($anime instanceof SeriesAnime && null !== $sample['episodesCount']) {
            $anime->setEpisodesCount($sample['episodesCount']);
        }

        foreach ($sample['genres'] as $code) {
            $anime->addGenre($code);
        }

        return $anime;
    }

    private function findOrCreateLabel(): Label
    {
        $label = $this->labels->findOneByName(self::LABEL_NAME);
        if (null !== $label) {
            return $label;
        }

        $label = new Label(self::LABEL_NAME);
        $this->entityManager->persist($label);

        return $label;
    }

    private function findOrCreateStudio(string $name): Studio
    {
        $studio = $this->studios->findOneByName($name);
        if (null !== $studio) {
            return $studio;
        }

        $studio = new Studio();
        $studio->rename($name);
        $this->entityManager->persist($studio);

        return $studio;
    }

    /**
     * Copies the cover asset shipped under public/sample/ (issue #181) into
     * %AppData%/media/{id}/ using the same app-media:// scheme as regular covers
     * (issue #68). The actual image files are added separately from this change (see
     * public/sample/README.md) — a missing source file is not fatal, the anime is still
     * created without a cover rather than failing the whole seed.
     */
    private function attachCover(Anime $anime, string $filename): void
    {
        $sourcePath = rtrim($this->sampleCoversDir, '/\\').'/'.$filename;
        if (!is_file($sourcePath)) {
            return;
        }

        $targetDir = rtrim($this->mediaDir, '/\\').'/'.$anime->id;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o777, true);
        }

        copy($sourcePath, $targetDir.'/'.$filename);
        $anime->setCover($filename);
    }
}
