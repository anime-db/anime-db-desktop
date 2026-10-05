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

use AnimeDb\PluginContracts\Sync\SyncInterface;
use App\Entity\ValueObject\PluginId;
use App\Message\SyncPullMessage;
use App\Message\SyncPullTickMessage;
use App\MessageHandler\SyncPullTickMessageHandler;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SyncRegistry;
use App\Service\Sync\SyncPullGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncPullTickMessageHandlerTest extends TestCase
{
    private const NOW = '2026-01-01T12:00:00+00:00';

    public function testQueuesAPullForEveryActiveSeededPluginWithoutALastPullMark(): void
    {
        $dispatched = $this->tick([
            'acme-a' => ['features' => ['sync' => true], 'syncSeeded' => true],
            'acme-b' => ['features' => ['sync' => true], 'syncSeeded' => true],
        ]);

        $this->assertSame(['acme-a', 'acme-b'], $dispatched);
    }

    public function testSkipsInactiveAndUnseededPlugins(): void
    {
        $dispatched = $this->tick([
            'acme-inactive' => ['features' => ['sync' => false], 'syncSeeded' => true],
            'acme-unseeded' => ['features' => ['sync' => true], 'syncSeeded' => false],
            'acme-noflag' => ['features' => ['sync' => true]],
        ]);

        $this->assertSame([], $dispatched);
    }

    public function testSkipsAPluginPulledLessThanSixHoursAgo(): void
    {
        $dispatched = $this->tick([
            'acme-fresh' => ['features' => ['sync' => true], 'syncSeeded' => true, 'syncLastPullAt' => '2026-01-01T06:00:01+00:00'],
        ]);

        $this->assertSame([], $dispatched);
    }

    public function testSkipsAPluginPulledExactlySixHoursAgo(): void
    {
        $dispatched = $this->tick([
            'acme-edge' => ['features' => ['sync' => true], 'syncSeeded' => true, 'syncLastPullAt' => '2026-01-01T06:00:00+00:00'],
        ]);

        $this->assertSame([], $dispatched);
    }

    public function testMarkPulledStoresUtcEvenWhenTheClockIsNotUtc(): void
    {
        $path = sys_get_temp_dir().'/anime-sync-pull-gate-test-'.uniqid().'.json';
        file_put_contents($path, (string) json_encode(['acme-a' => ['features' => ['sync' => true], 'syncSeeded' => true]]));
        $store = new PluginsConfigStore($path);

        (new SyncPullGate($store, new MockClock(new \DateTimeImmutable('2026-01-01T15:00:00+03:00'))))->markPulled(new PluginId('acme-a'));

        $this->assertSame('2026-01-01T12:00:00+00:00', $store->getPluginSettings(new PluginId('acme-a'))['syncLastPullAt']);
        @unlink($path);
    }

    #[DataProvider('dueMarks')]
    public function testQueuesAPullForAStaleUnparseableOrFutureMark(string $mark): void
    {
        $dispatched = $this->tick([
            'acme-due' => ['features' => ['sync' => true], 'syncSeeded' => true, 'syncLastPullAt' => $mark],
        ]);

        $this->assertSame(['acme-due'], $dispatched);
    }

    /** @return iterable<string, array{string}> */
    public static function dueMarks(): iterable
    {
        yield 'older than 6 hours' => ['2026-01-01T05:59:59+00:00'];
        yield 'unparseable' => ['yesterday-ish'];
        yield 'in the future' => ['2026-01-01T12:00:01+00:00'];
    }

    /**
     * @param array<string, array<string, mixed>> $settings every plugin is registered as a sync
     *
     * @return list<string> plugin ids of the dispatched SyncPullMessage
     */
    private function tick(array $settings): array
    {
        $path = sys_get_temp_dir().'/anime-sync-pull-tick-test-'.uniqid().'.json';
        file_put_contents($path, (string) json_encode($settings));
        $store = new PluginsConfigStore($path);

        $syncs = array_map(fn () => $this->createStub(SyncInterface::class), $settings);
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            \assert($message instanceof SyncPullMessage);
            $dispatched[] = $message->pluginId;

            return new Envelope($message);
        });

        $handler = new SyncPullTickMessageHandler(
            new SyncRegistry($syncs, $store),
            new SyncPullGate($store, new MockClock(new \DateTimeImmutable(self::NOW))),
            $bus,
        );
        $handler(new SyncPullTickMessage());
        @unlink($path);

        return $dispatched;
    }
}
