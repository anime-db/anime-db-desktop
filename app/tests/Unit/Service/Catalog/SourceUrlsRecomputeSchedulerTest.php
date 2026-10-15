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

namespace App\Tests\Unit\Service\Catalog;

use App\Command\SourceUrlsCheckCommand;
use App\Message\RecomputeSourceUrlsMessage;
use App\Service\Catalog\SourceUrlsFingerprintStore;
use App\Service\Catalog\SourceUrlsRecomputeScheduler;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\LinkRulesProvider;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Support\BuildsLinkRulesPlugins;
use App\Tests\Support\TemporaryDirectories;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SourceUrlsRecomputeSchedulerTest extends TestCase
{
    use BuildsLinkRulesPlugins;
    use TemporaryDirectories;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    public function testCommandQueuesTheMessageWhenThereIsNoStoredFingerprint(): void
    {
        $provider = $this->provider();
        $dispatched = [];
        $bus = $this->bus($dispatched);

        $tester = new CommandTester(new SourceUrlsCheckCommand($this->scheduler($provider, $this->connection(), $bus)));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(RecomputeSourceUrlsMessage::class, $dispatched[0]);
    }

    public function testCommandQueuesNothingWhenTheFingerprintMatches(): void
    {
        $provider = $this->provider();
        $connection = $this->connection();
        (new SourceUrlsFingerprintStore($connection))->set($provider->fingerprint());
        $dispatched = [];
        $bus = $this->bus($dispatched);

        (new CommandTester(new SourceUrlsCheckCommand($this->scheduler($provider, $connection, $bus))))->execute([]);

        $this->assertSame([], $dispatched);
    }

    public function testFingerprintLivesInTheDatabaseSoSwappingItReschedules(): void
    {
        $provider = $this->provider();
        $original = $this->connection();
        (new SourceUrlsFingerprintStore($original))->set($provider->fingerprint());
        // A restored backup: another data.db, with a fingerprint of another rule set.
        $restored = $this->connection();
        (new SourceUrlsFingerprintStore($restored))->set('fingerprint-of-another-rule-set');

        $dispatched = [];
        $bus = $this->bus($dispatched);
        $this->assertFalse($this->scheduler($provider, $original, $bus)->checkAndSchedule());
        $this->assertSame([], $dispatched);

        $this->assertTrue($this->scheduler($provider, $restored, $bus)->checkAndSchedule());
        $this->assertCount(1, $dispatched);
    }

    public function testFingerprintStoreOverwritesTheValue(): void
    {
        $store = new SourceUrlsFingerprintStore($this->connection());

        $this->assertNull($store->get());
        $store->set('a');
        $store->set('b');

        $this->assertSame('b', $store->get());
    }

    public function testReconcileDoesNotFailAndLogsWhenQueuingThrows(): void
    {
        $scheduler = $this->createMock(SourceUrlsRecomputeScheduler::class);
        $scheduler->expects($this->once())->method('checkAndSchedule')->willThrowException(new \RuntimeException('queue is down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $registry = new InstalledPluginsRegistry(
            $this->linkRulesPluginsDir(),
            new PluginsConfigStore($this->linkRulesPluginsDir().'/plugins.json'),
            $logger,
            sourceUrlsScheduler: $scheduler,
        );
        $registry->reconcile();

        $this->assertFileExists($this->linkRulesPluginsDir().'/installed-plugins.php');
    }

    private function provider(): LinkRulesProvider
    {
        $this->writeLinkRulesPlugin('acme-rules', ['^https://old\.example/(\d+)$' => 'https://new.example/$1']);

        return new LinkRulesProvider($this->linkRulesRegistry());
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE app_meta ("key" VARCHAR(64) NOT NULL, value CLOB NOT NULL, PRIMARY KEY ("key"))');

        return $connection;
    }

    /**
     * @param list<object> $dispatched
     */
    private function bus(array &$dispatched): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched) {
            $dispatched[] = $message;

            return new Envelope($message);
        });

        return $bus;
    }

    private function scheduler(LinkRulesProvider $provider, Connection $connection, MessageBusInterface $bus): SourceUrlsRecomputeScheduler
    {
        return new SourceUrlsRecomputeScheduler($provider, new SourceUrlsFingerprintStore($connection), $bus);
    }
}
