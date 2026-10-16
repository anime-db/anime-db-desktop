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

namespace App\Tests\Unit\MessageHandler;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Message\SaturateAnimeMessage;
use App\MessageHandler\SaturateAnimeMessageHandler;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Plugin\ExternalIdResolver;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\CachedFillerLookup;
use App\Service\Plugin\Filler\DescriptionRecalculationPlanner;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\Filler\SaturationQueue;
use App\Service\Plugin\Filler\SaturationService;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\PluginsConfigStore;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class SaturateAnimeMessageHandlerTest extends TestCase
{
    private EntityManager $entityManager;
    private JobLockService $lock;

    /** @var list<array{0: object, 1: array<array-key, object>}> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        foreach ([UnixTimestampType::NAME => UnixTimestampType::class, RatingType::NAME => RatingType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $liveness = $this->createStub(ProcessLivenessChecker::class);
        $liveness->method('getStartedAt')->willReturn(new \DateTimeImmutable('@0'));
        $this->lock = new JobLockService(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $liveness, new MockClock(new \DateTimeImmutable('@1000')), 30, 3);
        $this->dispatched = [];
        $this->fillerCalls = [];
    }

    /** @var list<array{id: string, locked: bool}> findById() calls of the stub filler */
    private array $fillerCalls = [];

    /** A filler that recognises every link and records whether the record's lock was held during its call. */
    private function filler(): FillerInterface
    {
        $filler = $this->createStub(FillerInterface::class);
        $filler->method('getFillableFields')->willReturn(['title', 'descriptions']);
        $filler->method('resolveExternalId')->willReturn('104');
        $filler->method('findById')->willReturnCallback(function (string $id): PluginAnimeData {
            $this->fillerCalls[] = ['id' => $id, 'locked' => $this->lock->isLocked('saturate:'.$this->currentId)];

            return new PluginAnimeData(title: 'Bleach', descriptions: ['ru' => 'Plugin text']);
        });

        return $filler;
    }

    private int $currentId = 0;

    private function handler(?EntityManagerInterface $handlerEntityManager = null): SaturateAnimeMessageHandler
    {
        $registry = new FillerRegistry(['animedb-shikimori' => $this->filler()], new PluginsConfigStore(sys_get_temp_dir().'/anime-saturate-handler-'.uniqid().'.json'));
        $repository = new AnimeRepository($this->entityManager);
        $resolver = new ExternalIdResolver($repository, new NullLogger());
        $settings = new AppSettingsProvider(new AppConfigStore(sys_get_temp_dir().'/anime-saturate-handler-'.uniqid().'.json'));
        $settings->setDescriptionSourcePriority(['animedb-shikimori']);
        $saturation = new SaturationService(
            $registry,
            $resolver,
            new BulkFillerService(
                $registry,
                new PluginAnimeDataMerger(new StudioRepository($this->entityManager), $this->entityManager, $this->createStub(PluginMediaDownloaderInterface::class), new NullLogger()),
                $this->entityManager,
                new NullLogger(),
                $this->createStub(MessageBusInterface::class),
                $repository,
                new CachedFillerLookup(new ArrayAdapter()),
            ),
            $this->entityManager,
            new NullLogger(),
            new DescriptionRecalculationPlanner($registry, $resolver, $settings, $this->entityManager),
        );
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $this->dispatched[] = [$message, $stamps];

            return new Envelope($message, $stamps);
        });

        return new SaturateAnimeMessageHandler($saturation, new SaturationQueue($bus), $this->lock, $handlerEntityManager ?? $this->entityManager, new NullLogger());
    }

    private function persistAnime(): int
    {
        $anime = (new TvAnime())->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        $anime->addSource('https://source.example/104');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $this->currentId = $anime->id ?? 0;

        return $this->currentId;
    }

    public function testBusyLockRequeuesIntoTheSameTransportWithA30SecondDelay(): void
    {
        $id = $this->persistAnime();
        $this->assertTrue($this->lock->acquire('saturate:'.$id));

        $message = new SaturateAnimeMessage($id, true, SaturateAnimeMessage::TRANSPORT_BULK);
        $this->handler()($message);

        $this->assertCount(1, $this->dispatched);
        [$requeued, $stamps] = $this->dispatched[0];
        $this->assertEquals($message, $requeued);
        $names = array_values(array_filter($stamps, static fn (object $s): bool => $s instanceof TransportNamesStamp));
        $this->assertSame(['saturation_bulk'], $names[0]->getTransportNames());
        $delays = array_values(array_filter($stamps, static fn (object $s): bool => $s instanceof DelayStamp));
        $this->assertSame(30000, $delays[0]->getDelay());
        $this->assertTrue($this->lock->isLocked('saturate:'.$id), 'the other run still owns the lock');
    }

    public function testFreeLockIsReleasedAfterTheRunAndNothingIsRequeued(): void
    {
        $id = $this->persistAnime();

        $this->handler()(new SaturateAnimeMessage($id));

        $this->assertSame([], $this->dispatched);
        $this->assertSame([['id' => '104', 'locked' => true]], $this->fillerCalls, 'the run happens exactly once, under the lock');
        $this->assertFalse($this->lock->isLocked('saturate:'.$id));
    }

    public function testForceRunsAgainForARowThatIsAlreadyFilledAndAPlainRunDoesNot(): void
    {
        $id = $this->persistAnime();
        $this->handler()(new SaturateAnimeMessage($id));
        $this->assertCount(1, $this->fillerCalls);

        $this->handler()(new SaturateAnimeMessage($id));
        $this->assertCount(1, $this->fillerCalls, 'filled_at is set, a plain run skips the plugin');

        $this->handler()(new SaturateAnimeMessage($id, true));
        $this->assertCount(2, $this->fillerCalls, 'force re-fills it');
        $this->assertFalse($this->lock->isLocked('saturate:'.$id));
    }

    public function testDeletedRecordIsDroppedWithoutAnException(): void
    {
        $this->handler()(new SaturateAnimeMessage(999));

        $this->assertSame([], $this->dispatched);
        $this->assertSame([], $this->fillerCalls);
        $this->assertFalse($this->lock->isLocked('saturate:999'));
    }

    public function testLockIsReleasedAndNothingIsThrownWhenTheRunThrows(): void
    {
        $id = $this->persistAnime();
        $failing = $this->createStub(EntityManagerInterface::class);
        $failing->method('isOpen')->willReturn(true);
        $failing->method('find')->willThrowException(new \RuntimeException('database is gone'));

        $this->handler($failing)(new SaturateAnimeMessage($id));

        $this->assertSame([], $this->dispatched);
        $this->assertSame([], $this->fillerCalls);
        $this->assertFalse($this->lock->isLocked('saturate:'.$id), 'the lock is released in finally');
    }

    public function testClosedEntityManagerDropsTheMessageWithoutAnException(): void
    {
        $id = $this->persistAnime();
        $this->entityManager->close();

        $this->handler()(new SaturateAnimeMessage($id));

        $this->assertSame([], $this->dispatched);
        $this->assertSame([], $this->fillerCalls);
        $this->assertFalse($this->lock->isLocked('saturate:'.$id));
    }

    public function testBusyLockRequeuesARecalculationIntoTheSameTransportKeepingItsMode(): void
    {
        $id = $this->persistAnime();
        $this->assertTrue($this->lock->acquire('saturate:'.$id));

        $message = new SaturateAnimeMessage($id, false, SaturateAnimeMessage::TRANSPORT_BULK, true);
        $this->handler()($message);

        $this->assertCount(1, $this->dispatched);
        [$requeued, $stamps] = $this->dispatched[0];
        $this->assertEquals($message, $requeued);
        $this->assertInstanceOf(SaturateAnimeMessage::class, $requeued);
        $this->assertTrue($requeued->descriptionsOnly);
        $names = array_values(array_filter($stamps, static fn (object $s): bool => $s instanceof TransportNamesStamp));
        $this->assertSame(['saturation_bulk'], $names[0]->getTransportNames());
        $delays = array_values(array_filter($stamps, static fn (object $s): bool => $s instanceof DelayStamp));
        $this->assertSame(30000, $delays[0]->getDelay());
        $this->assertSame([], $this->fillerCalls);
    }

    public function testARecalculationRunsUnderTheLockAndReleasesIt(): void
    {
        $id = $this->persistAnime();
        $anime = $this->entityManager->find(TvAnime::class, $id);
        $anime?->setDescription('ru', 'Hand written', null);
        $this->entityManager->flush();

        $this->handler()(new SaturateAnimeMessage($id, false, SaturateAnimeMessage::TRANSPORT_BULK, true));

        $this->assertSame([['id' => '104', 'locked' => true]], $this->fillerCalls);
        $this->assertSame([], $this->dispatched);
        $this->assertFalse($this->lock->isLocked('saturate:'.$id));
    }
}
