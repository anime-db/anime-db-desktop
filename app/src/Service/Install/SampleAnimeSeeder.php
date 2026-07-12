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
use App\Entity\Enum\AnimeNameType;
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
     * @var list<array{
     *     title: string,
     *     type: AnimeType,
     *     episodesCount: ?int,
     *     durationMinutes: ?int,
     *     studios: list<string>,
     *     genres: list<GenreCode>,
     *     cover: string,
     *     altNames: list<array{type: AnimeNameType, name: string}>,
     *     sources: list<string>,
     *     datePremiere: string,
     *     dateEnd: string,
     *     countries: list<string>,
     * }>
     */
    private const SAMPLES = [
        [
            'title' => 'Fullmetal Alchemist: Brotherhood',
            'type' => AnimeType::Tv,
            'episodesCount' => 64,
            'durationMinutes' => 24,
            'studios' => ['Bones'],
            'genres' => [GenreCode::Action, GenreCode::Adventure, GenreCode::Drama, GenreCode::Fantasy, GenreCode::Military, GenreCode::Shounen],
            'cover' => 'fullmetal-alchemist-brotherhood.webp',
            'altNames' => [
                ['type' => AnimeNameType::Original, 'name' => '鋼の錬金術師 FULLMETAL ALCHEMIST'],
                ['type' => AnimeNameType::Russian, 'name' => 'Стальной алхимик: Братство'],
            ],
            'sources' => ['https://myanimelist.net/anime/5114/Fullmetal_Alchemist__Brotherhood'],
            'datePremiere' => '2009-04-05',
            'dateEnd' => '2010-07-04',
            'countries' => ['JP'],
        ],
        [
            'title' => 'Spirited Away',
            'type' => AnimeType::Movie,
            'episodesCount' => null,
            'durationMinutes' => 125,
            'studios' => ['Studio Ghibli'],
            'genres' => [GenreCode::Adventure, GenreCode::Drama, GenreCode::Fantasy, GenreCode::Supernatural],
            'cover' => 'spirited-away.webp',
            'altNames' => [
                ['type' => AnimeNameType::Original, 'name' => '千と千尋の神隠し'],
                ['type' => AnimeNameType::Russian, 'name' => 'Унесённые призраками'],
            ],
            'sources' => ['https://myanimelist.net/anime/199/Sen_to_Chihiro_no_Kamikakushi'],
            'datePremiere' => '2001-07-20',
            'dateEnd' => '2001-07-20',
            'countries' => ['JP'],
        ],
        [
            'title' => 'Gintama',
            'type' => AnimeType::Tv,
            'episodesCount' => 201,
            'durationMinutes' => 24,
            'studios' => ['Sunrise'],
            'genres' => [GenreCode::Action, GenreCode::Comedy, GenreCode::Historical, GenreCode::Parody, GenreCode::SciFi, GenreCode::Shounen],
            'cover' => 'gintama.webp',
            'altNames' => [
                ['type' => AnimeNameType::Original, 'name' => '銀魂'],
                ['type' => AnimeNameType::Russian, 'name' => 'Гинтама'],
            ],
            'sources' => ['https://myanimelist.net/anime/918/Gintama'],
            'datePremiere' => '2006-04-04',
            'dateEnd' => '2010-03-25',
            'countries' => ['JP'],
        ],
        [
            'title' => 'Hellsing Ultimate',
            'type' => AnimeType::Ova,
            'episodesCount' => 10,
            'durationMinutes' => 49,
            'studios' => ['Madhouse', 'Satelight', 'Graphinica'],
            'genres' => [GenreCode::Action, GenreCode::Horror, GenreCode::Supernatural, GenreCode::Vampire, GenreCode::Seinen],
            'cover' => 'hellsing-ultimate.webp',
            'altNames' => [
                ['type' => AnimeNameType::Original, 'name' => 'ヘルシングOVA'],
                ['type' => AnimeNameType::Russian, 'name' => 'Хеллсинг: Ультимат'],
            ],
            'sources' => ['https://myanimelist.net/anime/1119/Hellsing_Ultimate'],
            'datePremiere' => '2006-02-10',
            'dateEnd' => '2012-12-27',
            'countries' => ['JP'],
        ],
        [
            'title' => 'Sousou no Frieren',
            'type' => AnimeType::Tv,
            'episodesCount' => 28,
            'durationMinutes' => 24,
            'studios' => ['Madhouse'],
            'genres' => [GenreCode::Adventure, GenreCode::Drama, GenreCode::Fantasy, GenreCode::Shounen],
            'cover' => 'sousou-no-frieren.webp',
            'altNames' => [
                ['type' => AnimeNameType::Original, 'name' => '葬送のフリーレン'],
                ['type' => AnimeNameType::Russian, 'name' => 'Провожающая в последний путь Фрирен'],
            ],
            'sources' => ['https://myanimelist.net/anime/52991/Sousou_no_Frieren'],
            'datePremiere' => '2023-09-29',
            'dateEnd' => '2024-03-22',
            'countries' => ['JP'],
        ],
        [
            'title' => 'One Punch Man',
            'type' => AnimeType::Tv,
            'episodesCount' => 12,
            'durationMinutes' => 24,
            'studios' => ['Madhouse'],
            'genres' => [GenreCode::Action, GenreCode::Comedy, GenreCode::SciFi, GenreCode::SuperPower, GenreCode::Seinen],
            'cover' => 'one-punch-man.webp',
            'altNames' => [
                ['type' => AnimeNameType::Original, 'name' => 'ワンパンマン'],
                ['type' => AnimeNameType::Russian, 'name' => 'Ванпанчмен'],
            ],
            'sources' => ['https://myanimelist.net/anime/30276/One_Punch_Man'],
            'datePremiere' => '2015-10-05',
            'dateEnd' => '2015-12-21',
            'countries' => ['JP'],
        ],
        [
            'title' => 'Solo Leveling',
            'type' => AnimeType::Tv,
            'episodesCount' => 12,
            'durationMinutes' => 23,
            'studios' => ['A-1 Pictures'],
            'genres' => [GenreCode::Action, GenreCode::Adventure, GenreCode::Fantasy],
            'cover' => 'solo-leveling.webp',
            'altNames' => [
                ['type' => AnimeNameType::Original, 'name' => '俺だけレベルアップな件'],
                ['type' => AnimeNameType::Russian, 'name' => 'Поднятие уровня в одиночку'],
            ],
            'sources' => ['https://myanimelist.net/anime/52299/Ore_dake_Level_Up_na_Ken'],
            'datePremiere' => '2024-01-06',
            'dateEnd' => '2024-03-30',
            'countries' => ['JP', 'KR'],
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
            $studios = [];
            foreach ($sample['studios'] as $name) {
                $studios[] = $studioCache[$name] ??= $this->findOrCreateStudio($name);
            }

            $anime = $this->buildAnime($sample, $studios);
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
     * @param array{title: string, type: AnimeType, episodesCount: ?int, durationMinutes: ?int, studios: list<string>, genres: list<GenreCode>, cover: string, altNames: list<array{type: AnimeNameType, name: string}>, sources: list<string>, datePremiere: string, dateEnd: string, countries: list<string>} $sample
     * @param list<Studio>                                                                                                                                                                                                                                                                                      $studios
     */
    private function buildAnime(array $sample, array $studios): Anime
    {
        $class = $sample['type']->entityClass();
        $anime = new $class();
        $anime->setTitle($sample['title'])
            ->setWatchStatus(WatchStatus::Plan)
            ->setDatePremiere(\DateTimeImmutable::createFromFormat('!Y-m-d', $sample['datePremiere']) ?: null)
            ->setDateEnd(\DateTimeImmutable::createFromFormat('!Y-m-d', $sample['dateEnd']) ?: null)
            ->setCountries($sample['countries']);

        if (null !== $sample['durationMinutes']) {
            $anime->setDurationMinutes($sample['durationMinutes']);
        }

        if ($anime instanceof SeriesAnime && null !== $sample['episodesCount']) {
            $anime->setEpisodesCount($sample['episodesCount']);
        }

        foreach ($studios as $studio) {
            $anime->addStudio($studio);
        }

        foreach ($sample['genres'] as $code) {
            $anime->addGenre($code);
        }

        foreach ($sample['altNames'] as $altName) {
            $anime->addName($altName['name'], $altName['type']);
        }

        foreach ($sample['sources'] as $url) {
            $anime->addSource($url);
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
