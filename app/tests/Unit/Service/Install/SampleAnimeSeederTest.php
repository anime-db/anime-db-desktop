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

namespace App\Tests\Unit\Service\Install;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\Label;
use App\Entity\SeriesAnime;
use App\Entity\Studio;
use App\Repository\LabelRepository;
use App\Repository\StudioRepository;
use App\Service\Install\SampleAnimeSeeder;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class SampleAnimeSeederTest extends TestCase
{
    private const SAMPLE_COUNT = 7;

    private EntityManager $entityManager;
    private string $mediaDir;
    private string $sampleCoversDir;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 4).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->mediaDir = sys_get_temp_dir().'/sample-seeder-media-'.uniqid();
        $this->sampleCoversDir = sys_get_temp_dir().'/sample-seeder-covers-'.uniqid();
        mkdir($this->mediaDir, 0o777, true);
        mkdir($this->sampleCoversDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->mediaDir);
        $this->removeDirectory($this->sampleCoversDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function createSeeder(): SampleAnimeSeeder
    {
        return new SampleAnimeSeeder(
            $this->entityManager,
            new StudioRepository($this->entityManager),
            new LabelRepository($this->entityManager),
            $this->mediaDir,
            $this->sampleCoversDir,
        );
    }

    public function testSeedCreatesAllSevenSamplesWithPlanWatchStatus(): void
    {
        $this->createSeeder()->seed();

        $animes = $this->entityManager->getRepository(Anime::class)->findAll();
        $this->assertCount(self::SAMPLE_COUNT, $animes);

        foreach ($animes as $anime) {
            $this->assertSame(WatchStatus::Plan, $anime->getWatchStatus());
            $this->assertNotEmpty($anime->getGenreCodes());
            $this->assertNotEmpty($anime->getStudios());
        }

        $titles = array_map(static fn (Anime $anime): string => $anime->getTitle(), $animes);
        $this->assertContains('Fullmetal Alchemist: Brotherhood', $titles);
        $this->assertContains('Spirited Away', $titles);
        $this->assertContains('Solo Leveling', $titles);
    }

    public function testSeedTagsEveryAnimeWithSampleLabel(): void
    {
        $this->createSeeder()->seed();

        $labels = $this->entityManager->getRepository(Label::class)->findAll();
        $this->assertCount(1, $labels);
        $this->assertSame('Sample', $labels[0]->name);
        $this->assertCount(self::SAMPLE_COUNT, $labels[0]->getAnimes());
    }

    public function testSeedReusesTheSameStudioAcrossMultipleTitles(): void
    {
        $this->createSeeder()->seed();

        $studios = $this->entityManager->getRepository(Studio::class)->findBy(['name' => 'Madhouse']);
        $this->assertCount(1, $studios);
        // Hellsing Ultimate, Sousou no Frieren and One Punch Man all use Madhouse.
        $this->assertCount(3, $studios[0]->getAnimes());
    }

    public function testSeedSetsMultipleStudiosOnHellsingUltimate(): void
    {
        $this->createSeeder()->seed();

        $animes = $this->entityManager->getRepository(Anime::class)->findBy(['title' => 'Hellsing Ultimate']);
        $this->assertCount(1, $animes);

        $names = array_map(static fn (Studio $studio): string => $studio->name, $animes[0]->getStudios()->toArray());
        $this->assertSame(['Madhouse', 'Satelight', 'Graphinica'], $names);
    }

    public function testSeedFillsAltNamesSourcesDatesAndCountries(): void
    {
        $this->createSeeder()->seed();

        $animes = $this->entityManager->getRepository(Anime::class)->findBy(['title' => 'Sousou no Frieren']);
        $this->assertCount(1, $animes);
        $anime = $animes[0];

        $this->assertCount(3, $anime->getNames());
        $this->assertCount(2, $anime->getSources());
        $this->assertSame('2023-09-29', $anime->getDatePremiere()?->format('Y-m-d'));
        $this->assertSame('2024-03-22', $anime->getDateEnd()?->format('Y-m-d'));
        $this->assertSame(['JP'], $anime->getCountries());
    }

    public function testSeedSetsEpisodesCountOnSeriesAndDurationOnMovie(): void
    {
        $this->createSeeder()->seed();

        $animes = $this->entityManager->getRepository(Anime::class)->findAll();
        $byTitle = [];
        foreach ($animes as $anime) {
            $byTitle[$anime->getTitle()] = $anime;
        }

        $movie = $byTitle['Spirited Away'];
        $this->assertSame(125, $movie->getDurationMinutes());

        $series = $byTitle['Gintama'];
        $this->assertInstanceOf(SeriesAnime::class, $series);
        $this->assertSame(201, $series->getEpisodesCount());
        $this->assertSame(24, $series->getDurationMinutes());
    }

    public function testSeedCopiesCoverIntoMediaDirWhenSourceFileExists(): void
    {
        file_put_contents($this->sampleCoversDir.'/spirited-away.webp', 'fake-cover-bytes');

        $this->createSeeder()->seed();

        $animes = $this->entityManager->getRepository(Anime::class)->findBy(['title' => 'Spirited Away']);
        $this->assertCount(1, $animes);
        $anime = $animes[0];

        $this->assertSame('spirited-away.webp', $anime->getCover());
        $copied = $this->mediaDir.'/'.$anime->id.'/spirited-away.webp';
        $this->assertFileExists($copied);
        $this->assertSame('fake-cover-bytes', file_get_contents($copied));
    }

    public function testSeedLeavesCoverNullWhenSourceFileIsMissing(): void
    {
        $this->createSeeder()->seed();

        $animes = $this->entityManager->getRepository(Anime::class)->findBy(['title' => 'Gintama']);
        $this->assertCount(1, $animes);

        $this->assertNull($animes[0]->getCover());
    }
}
