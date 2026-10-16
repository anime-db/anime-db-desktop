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
use App\Message\SaturateAnimeMessage;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\ExternalIdResolver;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\CachedFillerLookup;
use App\Service\Plugin\Filler\DescriptionRecalculationPlanner;
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
 * The description recalculation mode of {@see SaturationService} and the estimate of
 * {@see DescriptionRecalculationPlanner} (issue #1070), over in-memory SQLite with stub plugins.
 */
final class DescriptionRecalculationTest extends TestCase
{
    private EntityManager $entityManager;
    private string $dir;

    private int $sequence = 0;

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
        $this->sequence = 0;
        $this->dir = sys_get_temp_dir().'/anime-recalc-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    private function filler(string $name, string $text, string $host = 'none.invalid'): FillerInterface
    {
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title', 'durationMinutes', 'descriptions']);
        $filler->method('resolveExternalId')->willReturnCallback(static function (array $urls) use ($host): ?string {
            foreach ($urls as $url) {
                if (str_contains($url, $host)) {
                    return basename($url);
                }
            }

            return null;
        });
        $filler->method('findById')->willReturnCallback(function (string $id) use ($name, $text): PluginAnimeData {
            $this->calls[] = $name.':'.$id;

            return new PluginAnimeData(title: 'Other title', durationMinutes: 99, descriptions: ['ru' => $text, 'en' => $text]);
        });

        return $filler;
    }

    /**
     * @param array<string, FillerInterface> $fillers
     * @param list<string>                   $priority
     * @param array<string, mixed>           $pluginsConfig
     */
    private function planner(array $fillers, array $priority, array $pluginsConfig = []): DescriptionRecalculationPlanner
    {
        return $this->stack($fillers, $priority, $pluginsConfig)[1];
    }

    /**
     * @param array<string, FillerInterface> $fillers
     * @param list<string>                   $priority
     * @param array<string, mixed>           $pluginsConfig
     */
    private function service(array $fillers, array $priority, array $pluginsConfig = []): SaturationService
    {
        return $this->stack($fillers, $priority, $pluginsConfig)[0];
    }

    /**
     * @param array<string, FillerInterface> $fillers
     * @param list<string>                   $priority
     * @param array<string, mixed>           $pluginsConfig
     *
     * @return array{0: SaturationService, 1: DescriptionRecalculationPlanner}
     */
    private function stack(array $fillers, array $priority, array $pluginsConfig): array
    {
        $configPath = $this->dir.'/plugins-'.uniqid().'.json';
        file_put_contents($configPath, json_encode($pluginsConfig, \JSON_FORCE_OBJECT));
        $settings = new AppSettingsProvider(new AppConfigStore($this->dir.'/app-'.uniqid().'.json'));
        if ($priority !== []) {
            $settings->setDescriptionSourcePriority($priority);
        }
        $repository = new AnimeRepository($this->entityManager);
        $registry = new FillerRegistry($fillers, new PluginsConfigStore($configPath));
        $resolver = new ExternalIdResolver($repository, new NullLogger());
        $bulk = new BulkFillerService(
            $registry,
            new PluginAnimeDataMerger(new StudioRepository($this->entityManager), $this->entityManager, $this->createStub(PluginMediaDownloaderInterface::class), new NullLogger(), new PluginDescriptionWriter($this->entityManager, $settings)),
            $this->entityManager,
            new NullLogger(),
            $this->createStub(MessageBusInterface::class),
            $repository,
            new CachedFillerLookup(new ArrayAdapter()),
        );
        $planner = new DescriptionRecalculationPlanner($registry, $resolver, $settings, $this->entityManager);

        return [new SaturationService($registry, $resolver, $bulk, $this->entityManager, new NullLogger(), $planner), $planner];
    }

    /**
     * @param array<string, array{0: string, 1: ?string}> $rows locale => [text, source]
     * @param list<string>                                $ids  plugin ids with a stored, filled id (the record's ordinal number)
     */
    private function anime(array $rows, array $ids = [], string ...$urls): Anime
    {
        ++$this->sequence;
        $anime = new TvAnime();
        $anime->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        foreach ($urls as $url) {
            $anime->addSource($url);
        }
        foreach ($rows as $locale => [$text, $source]) {
            $anime->setDescription($locale, $text, $source);
        }
        foreach ($ids as $id) {
            $anime->rememberExternalId(new PluginId($id), (string) $this->sequence)->markExternalIdFilled(new PluginId($id));
        }
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime;
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    private function rows(Anime $anime): array
    {
        $result = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative('SELECT locale, description, source FROM anime_description WHERE anime_id = ? ORDER BY locale', [$anime->id]) as $row) {
            $result[$row['locale']] = [$row['description'], $row['source']];
        }

        return $result;
    }

    private function filledAt(Anime $anime, string $plugin): ?\DateTimeImmutable
    {
        return $anime->getExternalIdEntry(new PluginId($plugin))?->getFilledAt();
    }

    public function testRecalculationReplacesALowerSourceAndKeepsFilledAtAndOtherFields(): void
    {
        $anime = $this->anime(['ru' => ['Q text', 'plugin-q']], ['plugin-p', 'plugin-q']);
        $before = [$this->filledAt($anime, 'plugin-p'), $this->filledAt($anime, 'plugin-q')];
        $beats = 0;

        $calls = $this->service(['plugin-p' => $this->filler('p', 'P text'), 'plugin-q' => $this->filler('q', 'Q text')], ['plugin-p', 'plugin-q'])
            ->saturate($anime, false, function () use (&$beats): void {
                ++$beats;
            }, true);

        $this->assertSame(['p:1'], $this->calls);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $beats);
        $this->assertSame(['en' => ['P text', 'plugin-p'], 'ru' => ['P text', 'plugin-p']], $this->rows($anime));
        $this->assertEquals($before, [$this->filledAt($anime, 'plugin-p'), $this->filledAt($anime, 'plugin-q')]);
        $this->assertSame('Bleach', $anime->getTitle());
        $this->assertNull($anime->getDurationMinutes());
    }

    public function testHeartbeatFollowsEveryPluginCall(): void
    {
        $anime = $this->anime(['ru' => ['Hand', null]], ['plugin-p', 'plugin-q']);
        $beats = 0;

        $this->service(['plugin-p' => $this->filler('p', 'P text'), 'plugin-q' => $this->filler('q', 'Q text')], ['plugin-p', 'plugin-q'])
            ->saturate($anime, false, function () use (&$beats): void {
                ++$beats;
            }, true);

        $this->assertSame(['p:1', 'q:1'], $this->calls);
        $this->assertSame(2, $beats);
    }

    public function testASingleRowOfTheTopPluginDoesNotCallTheLowerOne(): void
    {
        $anime = $this->anime(['ru' => ['P text', 'plugin-p']], ['plugin-p', 'plugin-q']);

        $this->service(['plugin-p' => $this->filler('p', 'P text'), 'plugin-q' => $this->filler('q', 'Q text')], ['plugin-p', 'plugin-q'])->saturate($anime, false, null, true);

        $this->assertSame([], $this->calls);
    }

    public function testANullRowCallsEveryRankedPluginAttributesOnAnExactMatchAndLeavesADifferentText(): void
    {
        $anime = $this->anime(['ru' => ['Q text', null]], ['plugin-p', 'plugin-q']);

        $this->service(['plugin-p' => $this->filler('p', 'P text'), 'plugin-q' => $this->filler('q', 'Q text')], ['plugin-p', 'plugin-q'])->saturate($anime, false, null, true);

        $this->assertSame(['p:1', 'q:1'], $this->calls);
        $this->assertSame('Q text', $this->rows($anime)['ru'][0]);
        $this->assertSame('plugin-q', $this->rows($anime)['ru'][1]);
    }

    public function testANullRowWithADifferentTextIsNotChanged(): void
    {
        $anime = $this->anime(['ru' => ['Hand written', null]], ['plugin-p', 'plugin-q']);

        $this->service(['plugin-p' => $this->filler('p', 'P text'), 'plugin-q' => $this->filler('q', 'Q text')], ['plugin-p', 'plugin-q'])->saturate($anime, false, null, true);

        $this->assertSame(['p:1', 'q:1'], $this->calls);
        $this->assertSame(['Hand written', null], $this->rows($anime)['ru']);
    }

    public function testAManualLocaleStaysWhileAnotherLocaleOfTheSameRecordIsReplaced(): void
    {
        $anime = $this->anime(['ru' => ['Mine', 'manual'], 'en' => ['Q text', 'plugin-q']], ['plugin-p', 'plugin-q']);

        $this->service(['plugin-p' => $this->filler('p', 'P text'), 'plugin-q' => $this->filler('q', 'Q text')], ['plugin-p', 'plugin-q'])->saturate($anime, false, null, true);

        $this->assertSame(['p:1'], $this->calls);
        $this->assertSame(['en' => ['P text', 'plugin-p'], 'ru' => ['Mine', 'manual']], $this->rows($anime));
    }

    public function testARecordWithOnlyManualRowsCallsNothing(): void
    {
        $anime = $this->anime(['ru' => ['Mine', 'manual']], ['plugin-p']);

        $this->service(['plugin-p' => $this->filler('p', 'P text')], ['plugin-p'])->saturate($anime, false, null, true);

        $this->assertSame([], $this->calls);
    }

    public function testAPluginOutsideThePriorityIsNotCalled(): void
    {
        $anime = $this->anime(['ru' => ['Hand', null]], ['plugin-p', 'plugin-z']);

        $this->service(['plugin-p' => $this->filler('p', 'P text'), 'plugin-z' => $this->filler('z', 'Z text')], ['plugin-p'])->saturate($anime, false, null, true);

        $this->assertSame(['p:1'], $this->calls);
    }

    public function testASyncPluginWithTheFillerFeatureOffIsNotCalledButOthersAre(): void
    {
        $anime = $this->anime(['ru' => ['Q text', 'plugin-q']], ['plugin-p', 'plugin-q']);

        $this->service(
            ['plugin-p' => $this->filler('p', 'P text'), 'plugin-q' => $this->filler('q', 'Q text')],
            ['plugin-p', 'plugin-q'],
            ['plugin-p' => ['features' => ['filler' => false]]],
        )->saturate($anime, false, null, true);

        $this->assertSame([], $this->calls);
        $this->assertSame('Q text', $this->rows($anime)['ru'][0]);
    }

    public function testAnIdDerivedFromTheLinksIsUsedWhenThereIsNoStoredRow(): void
    {
        $anime = $this->anime(['ru' => ['Q text', 'plugin-q']], [], 'https://p.example/77');

        $this->service(['plugin-p' => $this->filler('p', 'P text', 'p.example')], ['plugin-p', 'plugin-q'])->saturate($anime, false, null, true);

        $this->assertSame(['p:77'], $this->calls);
        $this->assertSame('P text', $this->rows($anime)['ru'][0]);
    }

    public function testNoIdMeansNoCallAndNoTitleSearch(): void
    {
        $anime = $this->anime(['ru' => ['Q text', 'plugin-q']], [], 'https://other.example/1');
        $filler = $this->createMock(FillerInterface::class);
        $filler->expects($this->never())->method('find');
        $filler->expects($this->never())->method('findById');
        $filler->method('getFillableFields')->willReturn(['descriptions']);
        $filler->method('resolveExternalId')->willReturn(null);

        $this->service(['plugin-p' => $filler], ['plugin-p', 'plugin-q'])->saturate($anime, false, null, true);

        $this->assertSame('Q text', $this->rows($anime)['ru'][0]);
    }

    public function testTheEstimateCountsOnlyRecordsAPluginWouldBeCalledFor(): void
    {
        $replaceable = $this->anime(['ru' => ['Q text', 'plugin-q']], ['plugin-p']);
        $this->anime(['ru' => ['P text', 'plugin-p']], ['plugin-p']);          // already the top source
        $this->anime(['ru' => ['Mine', 'manual']], ['plugin-p']);              // only manual
        $this->anime(['ru' => ['Q text', 'plugin-q']]);                        // no id for P
        $derived = $this->anime(['ru' => ['Hand', null]], [], 'https://p.example/5'); // id from links
        $this->anime([], ['plugin-p']);                                        // no descriptions
        $this->entityManager->clear();

        $planner = $this->planner(['plugin-p' => $this->filler('p', 'P text', 'p.example')], ['plugin-p', 'plugin-q']);

        $this->assertSame([$replaceable->id, $derived->id], $planner->eligibleAnimeIds());
        $this->assertSame([], $this->calls, 'the estimate asks no plugin');
        $this->assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM anime_external_id WHERE anime_id = ?', [$derived->id]), 'the estimate stores no id');
    }

    public function testTheEstimateIsEmptyWithoutAPriority(): void
    {
        $this->anime(['ru' => ['Hand', null]], ['plugin-p']);

        $this->assertSame([], $this->planner(['plugin-p' => $this->filler('p', 'P text')], [])->eligibleAnimeIds());
    }

    public function testAMessageOfTheEarlierShapeUnserializesIntoAnOrdinarySaturation(): void
    {
        $class = SaturateAnimeMessage::class;
        $old = \sprintf('O:%d:"%s":3:{s:7:"animeId";i:5;s:5:"force";b:1;s:9:"transport";s:15:"saturation_bulk";}', \strlen($class), $class);

        $message = unserialize($old);

        $this->assertInstanceOf(SaturateAnimeMessage::class, $message);
        $this->assertSame(5, $message->animeId);
        $this->assertTrue($message->force);
        $this->assertSame(SaturateAnimeMessage::TRANSPORT_BULK, $message->transport);
        $this->assertFalse($message->descriptionsOnly);
        $this->assertEquals($message, unserialize(serialize($message)));
    }
}
