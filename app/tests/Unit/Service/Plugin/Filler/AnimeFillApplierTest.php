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

namespace App\Tests\Unit\Service\Plugin\Filler;

use AnimeDb\PluginContracts\Demographic as ContractsDemographic;
use AnimeDb\PluginContracts\GenreCode as ContractsGenreCode;
use AnimeDb\PluginContracts\PluginAnimeData;
use AnimeDb\PluginContracts\ThemeCode as ContractsThemeCode;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\Demographic;
use App\Entity\Enum\GenreCode;
use App\Entity\Enum\ThemeCode;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Repository\StudioRepository;
use App\Service\Plugin\Filler\AnimeFillApplier;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Real EntityManager/SQLite connection (same setup as ScanStorageServiceTest): applyStudios()
 * find-or-creates Studio rows through StudioRepository, which is not meaningfully mockable
 * without re-implementing its query.
 */
final class AnimeFillApplierTest extends TestCase
{
    private EntityManager $entityManager;
    private AnimeFillApplier $applier;

    protected function setUp(): void
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 5).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->applier = new AnimeFillApplier(new StudioRepository($this->entityManager), $this->entityManager);
    }

    private function newAnime(): TvAnime
    {
        $anime = new TvAnime();
        $anime->setTitle('Placeholder')->setWatchStatus(WatchStatus::Plan);

        return $anime;
    }

    public function testApplyUnionsAlternativeNamesWithoutDuplicatingExisting(): void
    {
        $anime = $this->newAnime();
        $anime->addName('Existing Synonym', \App\Entity\Enum\AnimeNameType::Synonym);

        $data = new PluginAnimeData(
            title: 'Bleach',
            alternativeNames: ['Existing Synonym', 'New Synonym'],
        );

        $this->applier->apply($anime, $data, ['alternativeNames']);

        $names = array_map(static fn ($n): string => $n->name, $anime->getNames()->toArray());
        $this->assertSame(['Existing Synonym', 'New Synonym'], $names);
    }

    public function testApplyMergesDescriptionsByLocaleWithoutLosingOtherLocales(): void
    {
        $anime = $this->newAnime();
        $anime->setDescription('en', 'English summary');

        $data = new PluginAnimeData(title: 'Bleach', descriptions: ['ru' => 'Русское описание']);

        $this->applier->apply($anime, $data, ['descriptions']);

        $this->assertSame('English summary', $anime->getSummary('en'));
        $this->assertSame('Русское описание', $anime->getSummary('ru'));
    }

    public function testApplyUnionsGenresMappedFromContractsEnum(): void
    {
        $anime = $this->newAnime();
        $anime->addGenre(GenreCode::Comedy);

        $data = new PluginAnimeData(title: 'Bleach', genres: [ContractsGenreCode::Action, ContractsGenreCode::Comedy]);

        $this->applier->apply($anime, $data, ['genres']);

        $this->assertSame([GenreCode::Comedy, GenreCode::Action], $anime->getGenreCodes());
    }

    public function testApplyUnionsThemesMappedFromContractsEnum(): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(title: 'Bleach', themes: [ContractsThemeCode::Isekai]);

        $this->applier->apply($anime, $data, ['themes']);

        $this->assertSame([ThemeCode::Isekai], $anime->getThemeCodes());
    }

    public function testApplyOverwritesDemographic(): void
    {
        $anime = $this->newAnime();
        $anime->setDemographic(Demographic::Kids);

        $data = new PluginAnimeData(title: 'Bleach', demographic: ContractsDemographic::Shounen);

        $this->applier->apply($anime, $data, ['demographic']);

        $this->assertSame(Demographic::Shounen, $anime->getDemographic());
    }

    public function testApplyCreatesStudioOnFirstUseAndReusesItOnSecondCall(): void
    {
        $data = new PluginAnimeData(title: 'Bleach', studios: ['Studio Pierrot']);

        $first = $this->newAnime();
        $this->applier->apply($first, $data, ['studios']);
        $this->entityManager->persist($first);
        $this->entityManager->flush();

        $second = $this->newAnime();
        $this->applier->apply($second, $data, ['studios']);

        $this->assertSame(
            $first->getStudios()->first()->id,
            $second->getStudios()->first()->id,
        );
    }

    public function testApplyUnionsCountriesWithoutDuplicates(): void
    {
        $anime = $this->newAnime();
        $anime->setCountries(['JP']);

        $data = new PluginAnimeData(title: 'Bleach', countries: ['JP', 'US']);

        $this->applier->apply($anime, $data, ['countries']);

        $this->assertSame(['JP', 'US'], $anime->getCountries());
    }

    public function testApplyOverwritesScalarDateAndDurationFields(): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(
            title: 'Bleach',
            datePremiere: new \DateTimeImmutable('2004-10-05'),
            dateEnd: new \DateTimeImmutable('2005-03-27'),
            durationMinutes: 24,
        );

        $this->applier->apply($anime, $data, ['datePremiere', 'dateEnd', 'durationMinutes']);

        $this->assertEquals(new \DateTimeImmutable('2004-10-05'), $anime->getDatePremiere());
        $this->assertEquals(new \DateTimeImmutable('2005-03-27'), $anime->getDateEnd());
        $this->assertSame(24, $anime->getDurationMinutes());
    }

    public function testApplyLeavesFieldUntouchedWhenPluginDataIsNull(): void
    {
        $anime = $this->newAnime();
        $anime->setDurationMinutes(24);

        $data = new PluginAnimeData(title: 'Bleach', durationMinutes: null);

        $this->applier->apply($anime, $data, ['durationMinutes']);

        $this->assertSame(24, $anime->getDurationMinutes());
    }

    public function testApplyIgnoresUnknownFieldNames(): void
    {
        $anime = $this->newAnime();

        $data = new PluginAnimeData(title: 'Bleach');

        $this->applier->apply($anime, $data, ['title', 'type', 'cover', 'images']);

        $this->assertSame('Placeholder', $anime->getTitle());
    }
}
