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

use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Message\SaturateAnimeMessage;
use App\MessageHandler\SaturateAnimeMessageHandler;
use App\Repository\AnimeRepository;
use App\Repository\StudioRepository;
use App\Service\JobLock\JobLockService;
use App\Service\JobLock\ProcessLivenessChecker;
use App\Service\Plugin\ExternalIdResolver;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\CachedFillerLookup;
use App\Service\Plugin\Filler\PluginAnimeDataMerger;
use App\Service\Plugin\Filler\PluginMediaDownloaderInterface;
use App\Service\Plugin\Filler\SaturationQueue;
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
    }

    private function handler(): SaturateAnimeMessageHandler
    {
        $registry = new FillerRegistry([], new PluginsConfigStore(sys_get_temp_dir().'/anime-saturate-handler-'.uniqid().'.json'));
        $repository = new AnimeRepository($this->entityManager);
        $saturation = new SaturationService(
            $registry,
            new ExternalIdResolver($repository, new NullLogger()),
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
        );
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $this->dispatched[] = [$message, $stamps];

            return new Envelope($message, $stamps);
        });

        return new SaturateAnimeMessageHandler($saturation, new SaturationQueue($bus), $this->lock, $this->entityManager, new NullLogger());
    }

    private function persistAnime(): int
    {
        $anime = (new TvAnime())->setTitle('Bleach')->setWatchStatus(WatchStatus::Plan);
        $this->entityManager->persist($anime);
        $this->entityManager->flush();

        return $anime->id ?? 0;
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
        $this->assertFalse($this->lock->isLocked('saturate:'.$id));
    }

    public function testDeletedRecordIsDroppedWithoutAnException(): void
    {
        $this->handler()(new SaturateAnimeMessage(999));

        $this->assertSame([], $this->dispatched);
        $this->assertFalse($this->lock->isLocked('saturate:999'));
    }

    public function testClosedEntityManagerDropsTheMessageWithoutAnException(): void
    {
        $id = $this->persistAnime();
        $this->entityManager->close();

        $this->handler()(new SaturateAnimeMessage($id));

        $this->assertSame([], $this->dispatched);
        $this->assertFalse($this->lock->isLocked('saturate:'.$id));
    }
}
