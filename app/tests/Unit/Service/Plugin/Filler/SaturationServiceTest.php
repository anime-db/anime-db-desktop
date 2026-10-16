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

namespace App\Tests\Unit\Service\Plugin\Filler;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Anime;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\ExternalIdResolver;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\CachedFillerLookup;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginDescriptionWriter;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\Filler\SaturationService;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Real BulkFillerService/ExternalIdResolver/EntityManager over in-memory SQLite; only the plugins
 * are stubs, and the "calls" are their findById() invocations.
 */
final class SaturationServiceTest extends TestCase
{
    private EntityManager $entityManager;

    /** @var list<string> "plugin:externalId" per findById() call, in order */
    private array $calls = [];

    protected function setUp(): void
    {
        foreach ([UnixTimestampType::NAME => UnixTimestampType::class, RatingType::NAME => RatingType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 5).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        $this->calls = [];
    }

    /**
     * @param string $host a link on this host makes the plugin recognise id $id
     */
    private function filler(string $name, string $host, ?string $addsUrl = null, bool $throws = false): FillerInterface
    {
        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->never())->method('find');
        $filler->method('getFillableFields')->willReturn(['externalUrls']);
        $filler->method('resolveExternalId')->willReturnCallback(static function (array $urls) use ($host): ?string {
            foreach ($urls as $url) {
                if (str_contains($url, $host)) {
                    return basename($url);
                }
            }

            return null;
        });
        $filler->method('findById')->willReturnCallback(function (string $id) use ($name, $addsUrl, $throws): PluginAnimeData {
            $this->calls[] = $name.':'.$id;
            if ($throws) {
                throw new \RuntimeException('banned');
            }

            return new PluginAnimeData(title: 'x', externalUrls: $addsUrl !== null ? [$addsUrl] : null);
        });

        return $filler;
    }

    /** @param array<string, FillerInterface> $fillers */
    private function service(array $fillers): SaturationService
    {
        $repository = new AnimeRepository($this->entityManager);
        $bulk = new BulkFillerService(
            new FillerRegistry($fillers, new PluginsConfigStore(sys_get_temp_dir().'/anime-saturation-test-'.uniqid().'.json')),
            new PluginAnimeDataMerger(new StudioRepository($this->entityManager), $this->entityManager, $this->createStub(PluginMediaDownloaderInterface::class), new NullLogger()),
            $this->entityManager,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $repository,
            new CachedFillerLookup(new ArrayAdapter()),
        );

        return new SaturationService(
            new FillerRegistry($fillers, new PluginsConfigStore(sys_get_temp_dir().'/anime-saturation-test-'.uniqid().'.json')),
            new ExternalIdResolver($repository, new NullLogger()),
            $bulk,
            $this->entityManager,
            new NullLogger(),
        );
    }

    private function anime(string ...$urls): Anime
    {
        $anime = new TvAnime();
        $anime->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        foreach ($urls as $url) {
            $anime->addSource($url);
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    private function filled(Anime $anime, string $plugin): bool
    {
        return $anime->getExternalIdEntry(new PluginId($plugin))?->getFilledAt() !== null;
    }

    public function testFilledRowIsSkippedSeededRowUsesItsOwnIdAndMissingRowIsResolvedFromLinks(): void
    {
        $anime = $this->anime('https://c.example/1');
        $anime->rememberExternalId(new PluginId('plugin-a'), '11')->markExternalIdFilled(new PluginId('plugin-a'));
        $anime->rememberExternalId(new PluginId('plugin-b'), '22'); // seeded, no link on the record
        $this->entityManager->flush();

        $this->service([
            'plugin-a' => $this->filler('a', 'a.example'),
            'plugin-b' => $this->filler('b', 'b.example'),
            'plugin-c' => $this->filler('c', 'c.example'),
            'plugin-d' => $this->filler('d', 'd.example'),
        ])->saturate($anime);

        $this->assertSame(['b:22', 'c:1'], $this->calls);
        $this->assertTrue($this->filled($anime, 'plugin-b'));
        $this->assertTrue($this->filled($anime, 'plugin-c'));
        $this->assertNull($anime->getExternalIdEntry(new PluginId('plugin-d')));
    }

    public function testEveryFillerIsCalledAtMostOnceEvenAfterAFailureOrWhenLinksAppearLater(): void
    {
        $anime = $this->anime('https://a.example/1');

        $calls = $this->service(['plugin-a' => $this->filler('a', 'a.example', 'https://a.example/other')])->saturate($anime);

        $this->assertSame(1, $calls);

        $this->calls = [];
        $failing = $this->anime('https://a.example/2');
        $this->service(['plugin-a' => $this->filler('a', 'a.example', throws: true)])->saturate($failing);
        $this->assertSame(['a:2'], $this->calls);
        $this->assertFalse($this->filled($failing, 'plugin-a'));
    }

    public function testAChainIsFollowedToItsEnd(): void
    {
        $anime = $this->anime('https://c.example/1');
        // Registry order is A, B, C: A recognises only what C adds, B only what A adds.
        $this->service([
            'plugin-a' => $this->filler('a', 'a.example', 'https://b.example/1'),
            'plugin-b' => $this->filler('b', 'b.example'),
            'plugin-c' => $this->filler('c', 'c.example', 'https://a.example/1'),
        ])->saturate($anime);

        $this->assertSame(['c:1', 'a:1', 'b:1'], $this->calls);
    }

    public function testASaturatedRecordMakesNoCallsAndForceCallsFilledRowsOnce(): void
    {
        $anime = $this->anime('https://a.example/1');
        $fillers = ['plugin-a' => $this->filler('a', 'a.example')];

        $this->service($fillers)->saturate($anime);
        $this->assertSame(['a:1'], $this->calls);

        $this->assertSame(0, $this->service($fillers)->saturate($anime));
        $this->assertSame(['a:1'], $this->calls);

        // A fresh service: the findById() result cache of the first one would hide the call.
        $this->service($fillers)->saturate($anime, force: true);
        $this->assertSame(['a:1', 'a:1'], $this->calls);
    }

    public function testForceCallsASeededPluginWhoseLinkIsNotOnTheRecord(): void
    {
        $anime = $this->anime();
        $anime->rememberExternalId(new PluginId('plugin-a'), '9')->markExternalIdFilled(new PluginId('plugin-a'));
        $this->entityManager->flush();

        $this->service(['plugin-a' => $this->filler('a', 'a.example')])->saturate($anime, force: true);

        $this->assertSame(['a:9'], $this->calls);
    }

    public function testForcedSaturationReplacesALowerRankedDescription(): void
    {
        $configPath = sys_get_temp_dir().'/anime-saturation-priority-'.uniqid().'.json';
        $settings = new AppSettingsProvider(new AppConfigStore($configPath));
        $settings->setDescriptionSourcePriority(['plugin-a', 'plugin-b']);

        $anime = $this->anime('https://a.example/1');
        $anime->setDescription('ru', 'Old text', 'plugin-b');
        $this->entityManager->flush();

        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['descriptions']);
        $filler->method('resolveExternalId')->willReturn('1');
        $filler->method('findById')->willReturn(new PluginAnimeData(title: 'x', descriptions: ['ru' => 'New text']));
        $fillers = ['plugin-a' => $filler];
        $repository = new AnimeRepository($this->entityManager);
        $registry = new FillerRegistry($fillers, new PluginsConfigStore(sys_get_temp_dir().'/anime-saturation-test-'.uniqid().'.json'));
        $bulk = new BulkFillerService(
            $registry,
            new PluginAnimeDataMerger(new StudioRepository($this->entityManager), $this->entityManager, $this->createStub(PluginMediaDownloaderInterface::class), new NullLogger(), new PluginDescriptionWriter($this->entityManager, $settings)),
            $this->entityManager,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $repository,
            new CachedFillerLookup(new ArrayAdapter()),
        );

        (new SaturationService($registry, new ExternalIdResolver($repository, new NullLogger()), $bulk, $this->entityManager, new NullLogger()))->saturate($anime, force: true);

        $row = $this->entityManager->getConnection()->fetchAssociative('SELECT description, source FROM anime_description WHERE anime_id = ?', [$anime->id]);
        $this->assertSame(['description' => 'New text', 'source' => 'plugin-a'], $row);
        @unlink($configPath);
        @unlink($configPath.'.lock');
    }

    public function testIdConflictIsLoggedTheMarkerStaysUnsetAndTheOtherPluginsRun(): void
    {
        $owner = $this->anime();
        $owner->rememberExternalId(new PluginId('plugin-a'), '1');
        $this->entityManager->flush();
        $anime = $this->anime('https://a.example/1', 'https://b.example/1');

        $this->service(['plugin-a' => $this->filler('a', 'a.example'), 'plugin-b' => $this->filler('b', 'b.example')])->saturate($anime);

        $this->assertSame(['b:1'], $this->calls);
        $this->assertFalse($this->filled($anime, 'plugin-a'));
        $this->assertTrue($this->filled($anime, 'plugin-b'));
    }

    public function testAClosedEntityManagerAbortsTheRunAndHeartbeatFollowsEveryCall(): void
    {
        $anime = $this->anime('https://a.example/1', 'https://b.example/1');
        $beats = 0;

        $this->service(['plugin-a' => $this->filler('a', 'a.example'), 'plugin-b' => $this->filler('b', 'b.example')])
            ->saturate($anime, false, function () use (&$beats): void {
                ++$beats;
                $this->entityManager->close();
            });

        $this->assertSame(['a:1'], $this->calls);
        $this->assertSame(1, $beats);
    }
}
