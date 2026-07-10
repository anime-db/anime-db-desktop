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

namespace App\Tests\Unit\Service\Storage;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\AnimeNameType;
use App\Entity\Enum\StorageType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\Storage;
use App\Entity\TvAnime;
use App\Repository\AnimeRepository;
use App\Service\Storage\OrphanAnimeMatcher;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Exercises OrphanAnimeMatcher against a real EntityManager/SQLite connection (same setup as
 * AnimeRepositoryTest): matching needs to see both the anime.title column and the joined
 * anime_name rows, which a mocked EntityManager cannot express.
 */
final class OrphanAnimeMatcherTest extends TestCase
{
    private EntityManager $entityManager;
    private OrphanAnimeMatcher $matcher;

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

        $this->matcher = new OrphanAnimeMatcher(new AnimeRepository($this->entityManager));
    }

    public function testMatchesByTitleCaseAndWhitespaceInsensitively(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy   Bebop')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $candidates = $this->matcher->findCandidates('cowboy bebop');

        $this->assertSame([$anime], $candidates);
    }

    public function testMatchesTitleWithUppercasedNonAsciiLetter(): void
    {
        // SQLite's LOWER() only folds ASCII letters, so this would miss a title starting
        // with an uppercased macron'd romaji vowel if matching relied on SQL-side LOWER()
        // instead of the mb_strtolower()-based NameNormalizer applied at write time.
        $anime = new TvAnime();
        $anime->setTitle('Ōkami')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $candidates = $this->matcher->findCandidates('ōkami');

        $this->assertSame([$anime], $candidates);
    }

    public function testMatchesByAlternativeName(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $anime->addName('Kaubōi Bibappu', AnimeNameType::Synonym);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $candidates = $this->matcher->findCandidates('kaubōi bibappu');

        $this->assertSame([$anime], $candidates);
    }

    public function testReturnsEmptyListWhenNothingMatches(): void
    {
        $anime = new TvAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        $candidates = $this->matcher->findCandidates('trigun');

        $this->assertSame([], $candidates);
    }

    public function testReturnsMultipleCandidatesForSeveralOrphans(): void
    {
        $first = new TvAnime();
        $first->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $second = new MovieAnime();
        $second->setTitle('Trigun the Movie');
        $second->setWatchStatus(WatchStatus::Plan);
        $second->addName('Trigun', AnimeNameType::Synonym);
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->flush();

        $candidates = $this->matcher->findCandidates('trigun');

        $this->assertSame([$first, $second], $candidates);
    }

    public function testIgnoresAnimeAlreadyLinkedToStorage(): void
    {
        $storage = new Storage('Main folder', sys_get_temp_dir(), StorageType::Folder);
        $this->entityManager->persist($storage);

        $linked = new TvAnime();
        $linked->setTitle('Trigun')->setWatchStatus(WatchStatus::Plan);
        $linked->setStorage($storage)->setStoragePath('Trigun');
        $this->entityManager->persist($linked);
        $this->entityManager->flush();

        $candidates = $this->matcher->findCandidates('trigun');

        $this->assertSame([], $candidates);
    }
}
