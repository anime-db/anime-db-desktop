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

namespace App\Tests\Unit\Entity;

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\AnimeType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\MusicAnime;
use App\Entity\OnaAnime;
use App\Entity\OvaAnime;
use App\Entity\SpecialAnime;
use App\Entity\TvAnime;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the discriminator-collapse bug behind the #58 STI redesign (see
 * .claude-docs/gotchas.md, "История решения..."). The rejected first version mapped
 * several `type` values onto one shared SeriesAnime class; Doctrine only supports one
 * discriminatorValue per class on write, so any persisted SeriesAnime silently collapsed
 * to a single stored type regardless of what it actually was. This test round-trips all
 * six leaf classes through a real EntityManager/SQLite connection and asserts the row
 * reloads as the same concrete class it was persisted as.
 */
final class AnimeDiscriminatorPersistenceTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    /** @return array<string, array{class-string<Anime>, AnimeType}> */
    public static function discriminatorValues(): array
    {
        return [
            MovieAnime::class => [MovieAnime::class, AnimeType::Movie],
            TvAnime::class => [TvAnime::class, AnimeType::Tv],
            OvaAnime::class => [OvaAnime::class, AnimeType::Ova],
            OnaAnime::class => [OnaAnime::class, AnimeType::Ona],
            SpecialAnime::class => [SpecialAnime::class, AnimeType::Special],
            MusicAnime::class => [MusicAnime::class, AnimeType::Music],
        ];
    }

    /**
     * @param class-string<Anime> $class
     */
    #[DataProvider('discriminatorValues')]
    public function testPersistAndReloadKeepsConcreteClass(string $class, AnimeType $expectedType): void
    {
        $anime = new $class();
        $anime->setTitle('Round trip');
        $anime->setWatchStatus(WatchStatus::Plan);

        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $id = $anime->id;

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Anime::class, $id);

        $this->assertInstanceOf($class, $reloaded);
        $this->assertSame($expectedType, $reloaded->getType());
    }
}
