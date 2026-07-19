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

use AnimeDb\PluginContracts\AnimeType as ContractsAnimeType;
use AnimeDb\PluginContracts\FillerInterface;
use AnimeDb\PluginContracts\PluginAnimeData;
use AnimeDb\PluginContracts\SearchByPluginCandidate as ContractsSearchByPluginCandidate;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\MovieAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\StudioRepository;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class BulkFillerServiceTest extends TestCase
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

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 5).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @param iterable<string, FillerInterface> $fillers
     */
    private function newService(iterable $fillers, ?LoggerInterface $logger = null): BulkFillerService
    {
        return new BulkFillerService(
            new FillerRegistry($fillers, new PluginsConfigStore(sys_get_temp_dir().'/anime-bulk-filler-test-'.uniqid().'.json')),
            new PluginAnimeDataMerger(
                new StudioRepository($this->entityManager),
                $this->entityManager,
                $this->createStub(PluginMediaDownloaderInterface::class),
            ),
            $this->entityManager,
            $logger ?? new NullLogger(),
        );
    }

    public function testFillNewFromPluginReturnsNullWhenNoFillerIsRegistered(): void
    {
        $service = $this->newService([]);

        $this->assertNull($service->fillNewFromPlugin(new PluginId('animedb-shikimori'), 'Bleach'));
    }

    public function testFillNewFromPluginReturnsNullWhenPluginCannotFindTheTitle(): void
    {
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willReturn([]);

        $service = $this->newService(['animedb-shikimori' => $filler]);

        $this->assertNull($service->fillNewFromPlugin(new PluginId('animedb-shikimori'), 'Bleach'));
    }

    public function testFillNewFromPluginCreatesAnimeOfPluginReportedTypeAndRemembersExternalId(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach: Memories of Nobody', type: ContractsAnimeType::Movie, durationMinutes: 91);

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willReturn([new ContractsSearchByPluginCandidate((string) $pluginId, 'Bleach: Memories of Nobody', '104')]);
        $filler->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type', 'durationMinutes']);

        $service = $this->newService([(string) $pluginId => $filler]);

        $anime = $service->fillNewFromPlugin($pluginId, 'Bleach: Memories of Nobody');

        $this->assertInstanceOf(MovieAnime::class, $anime);
        $this->assertSame('Bleach: Memories of Nobody', $anime->getTitle());
        $this->assertSame(91, $anime->getDurationMinutes());
        $this->assertSame('104', $anime->getExternalId($pluginId, $filler));
    }

    public function testFillNewFromPluginCallsFindByIdOnlyOnceForRepeatedExternalId(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach');

        $filler = $this->createMock(FillerInterface::class);
        $filler->method('find')->willReturn([new ContractsSearchByPluginCandidate((string) $pluginId, 'Bleach', '104')]);
        $filler->expects($this->once())->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title']);

        $service = $this->newService([(string) $pluginId => $filler]);

        $service->fillNewFromPlugin($pluginId, 'Bleach');
        $service->fillNewFromPlugin($pluginId, 'Bleach');
    }

    public function testFillNewFromPluginSkipsFindWhenExternalIdIsAlreadyKnown(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach: Memories of Nobody', type: ContractsAnimeType::Movie, durationMinutes: 91);

        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->never())->method('find');
        $filler->expects($this->once())->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type', 'durationMinutes']);

        $service = $this->newService([(string) $pluginId => $filler]);

        $anime = $service->fillNewFromPlugin($pluginId, 'Bleach: Memories of Nobody', '104');

        $this->assertInstanceOf(MovieAnime::class, $anime);
        $this->assertSame('104', $anime->getExternalId($pluginId, $filler));
    }

    public function testFillNewFromPluginReturnsNullAndLogsAWarningWhenFindThrows(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('find')->willThrowException(new \RuntimeException('external source unreachable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('bulk-fill'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === (string) $pluginId
                && $context['exception'] instanceof \RuntimeException),
        );

        $service = $this->newService([(string) $pluginId => $filler], $logger);

        $this->assertNull($service->fillNewFromPlugin($pluginId, 'Bleach'));
    }

    public function testFillNewFromPluginReturnsNullAndLogsAWarningWhenFindByIdThrowsForAnAlreadyKnownExternalId(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->willThrowException(new \RuntimeException('external source unreachable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('bulk-fill'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === (string) $pluginId
                && $context['exception'] instanceof \RuntimeException),
        );

        $service = $this->newService([(string) $pluginId => $filler], $logger);

        $this->assertNull($service->fillNewFromPlugin($pluginId, 'Bleach', '104'));
    }

    /**
     * fillNewFrom() (issue #257 review, pull-sync) resolves directly by external id — no
     * find()-by-title round trip — and works off a filler instance the caller already has in
     * hand, bypassing FillerRegistry entirely: the empty registry below proves that.
     */
    public function testFillNewFromResolvesByExternalIdWithoutGoingThroughTheFillerRegistry(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        $data = new PluginAnimeData(title: 'Bleach: Memories of Nobody', type: ContractsAnimeType::Movie, durationMinutes: 91);

        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->never())->method('find');
        $filler->expects($this->once())->method('findById')->with('104')->willReturn($data);
        $filler->method('getFillableFields')->willReturn(['title', 'type', 'durationMinutes']);

        $service = $this->newService([]);

        $anime = $service->fillNewFrom($filler, $pluginId, '104');

        $this->assertInstanceOf(MovieAnime::class, $anime);
        $this->assertSame('Bleach: Memories of Nobody', $anime->getTitle());
        $this->assertSame(91, $anime->getDurationMinutes());
        $this->assertSame('104', $anime->getExternalId($pluginId, $filler));
    }

    public function testFillNewFromReturnsNullWhenTheFillerCannotResolveTheExternalId(): void
    {
        $pluginId = new PluginId('animedb-shikimori');

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('findById')->willReturn(null);

        $service = $this->newService([]);

        $this->assertNull($service->fillNewFrom($filler, $pluginId, '104'));
    }
}
