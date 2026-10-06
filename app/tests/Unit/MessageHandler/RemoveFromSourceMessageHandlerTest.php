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

use AnimeDb\PluginContracts\OAuth\ReauthRequiredException;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Sync\SyncRemovalInterface;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\TvAnime;
use App\Entity\ValueObject\PluginId;
use App\Message\RemoveFromSourceMessage;
use App\Message\SyncSeedMessage;
use App\MessageHandler\RemoveFromSourceMessageHandler;
use App\Repository\AnimeRepository;
use App\Repository\SyncTombstoneRepository;
use App\Service\Sync\SourceRemovalService;
use App\Tests\Support\BuildsAnimeDeleteService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class RemoveFromSourceMessageHandlerTest extends TestCase
{
    use BuildsAnimeDeleteService;

    private const PLUGIN = 'acme-list';

    private EntityManager $entityManager;
    private SyncTombstoneRepository $tombstones;

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
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
        $this->tombstones = new SyncTombstoneRepository($this->entityManager);
    }

    private function handler(?SyncInterface $sync, ?\App\Service\JobLock\JobLockService $locks = null): RemoveFromSourceMessageHandler
    {
        $registry = $sync === null
            ? $this->newSyncRegistryOf([], [])
            : $this->newSyncRegistryOf([self::PLUGIN => $sync], [self::PLUGIN]);

        return new RemoveFromSourceMessageHandler(
            $registry,
            new SourceRemovalService($this->tombstones, new AnimeRepository($this->entityManager), new NullLogger()),
            $locks ?? $this->newJobLockService(),
            new NullLogger(),
        );
    }

    private function pendingTombstone(string $externalId = '42', bool $pending = true): void
    {
        $this->tombstones->record(self::PLUGIN, $externalId, new \DateTimeImmutable(), $pending);
    }

    private function hasTombstone(string $externalId = '42'): bool
    {
        return $this->tombstones->exists(self::PLUGIN, $externalId);
    }

    public function testRemovesOnceWithTheExternalIdAndDropsTheTombstone(): void
    {
        $this->pendingTombstone();
        $sync = $this->createMock(SyncRemovalInterface::class);
        $sync->expects($this->once())->method('remove')->with('42');

        $this->handler($sync)(new RemoveFromSourceMessage(self::PLUGIN, '42'));

        $this->assertFalse($this->hasTombstone());
    }

    public function testATransientFailureKeepsTheTombstoneWithItsFlagAndPropagatesForTheRetry(): void
    {
        $this->pendingTombstone();
        $sync = $this->createStub(SyncRemovalInterface::class);
        $sync->method('remove')->willThrowException(new \RuntimeException('network down'));

        try {
            $this->handler($sync)(new RemoveFromSourceMessage(self::PLUGIN, '42'));
            $this->fail('The failure must reach the transport retry strategy.');
        } catch (\RuntimeException) {
        }

        $this->assertTrue($this->tombstones->isRemovalPending(self::PLUGIN, '42'));
    }

    public function testReauthIsUnrecoverableAndKeepsTheFlag(): void
    {
        $this->pendingTombstone();
        $sync = $this->createStub(SyncRemovalInterface::class);
        $sync->method('remove')->willThrowException(new ReauthRequiredException('expired'));

        try {
            $this->handler($sync)(new RemoveFromSourceMessage(self::PLUGIN, '42'));
            $this->fail('A dead authorization must not be retried.');
        } catch (UnrecoverableMessageHandlingException) {
        }

        $this->assertTrue($this->tombstones->isRemovalPending(self::PLUGIN, '42'));
    }

    public function testALiveEntryHoldingThePairClearsTheFlagAndRemovesNothing(): void
    {
        $this->pendingTombstone();
        $anime = new TvAnime();
        $anime->setTitle('Added again')->setWatchStatus(WatchStatus::Plan);
        $anime->rememberExternalId(new PluginId(self::PLUGIN), '42');
        $this->entityManager->persist($anime);
        $this->entityManager->flush();
        $sync = $this->createMock(SyncRemovalInterface::class);
        $sync->expects($this->never())->method('remove');

        $this->handler($sync)(new RemoveFromSourceMessage(self::PLUGIN, '42'));

        $this->assertFalse($this->tombstones->isRemovalPending(self::PLUGIN, '42'));
        $this->assertTrue($this->hasTombstone(), 'The tombstone itself stays, only the flag goes.');
    }

    public function testATombstoneWithoutTheFlagIsNeverRemovedOnTheSource(): void
    {
        $this->pendingTombstone(pending: false);
        $sync = $this->createMock(SyncRemovalInterface::class);
        $sync->expects($this->never())->method('remove');

        $this->handler($sync)(new RemoveFromSourceMessage(self::PLUGIN, '42'));

        $this->assertTrue($this->hasTombstone());
    }

    public function testAMissingTombstoneIsIgnored(): void
    {
        $sync = $this->createMock(SyncRemovalInterface::class);
        $sync->expects($this->never())->method('remove');

        $this->handler($sync)(new RemoveFromSourceMessage(self::PLUGIN, '42'));

        $this->assertFalse($this->hasTombstone());
    }

    public function testAnInactivePluginLeavesTheFlag(): void
    {
        $this->pendingTombstone();

        $this->handler(null)(new RemoveFromSourceMessage(self::PLUGIN, '42'));

        $this->assertTrue($this->tombstones->isRemovalPending(self::PLUGIN, '42'));
    }

    public function testAPluginWithoutRemovalLeavesTheFlag(): void
    {
        $this->pendingTombstone();

        $this->handler($this->createStub(SyncInterface::class))(new RemoveFromSourceMessage(self::PLUGIN, '42'));

        $this->assertTrue($this->tombstones->isRemovalPending(self::PLUGIN, '42'));
    }

    public function testASeedLockSkipsTheRemoval(): void
    {
        $this->pendingTombstone();
        $locks = $this->newJobLockService();
        $locks->acquire(SyncSeedMessage::jobKey(self::PLUGIN));
        $sync = $this->createMock(SyncRemovalInterface::class);
        $sync->expects($this->never())->method('remove');

        $this->handler($sync, $locks)(new RemoveFromSourceMessage(self::PLUGIN, '42'));

        $this->assertTrue($this->tombstones->isRemovalPending(self::PLUGIN, '42'));
    }
}
