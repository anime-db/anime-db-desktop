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

namespace App\Tests\Unit\Service\Plugin;

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Reproduces the exact race issue #219 calls out: two writers (HTTP worker + background
 * consumer) doing read -> modify -> write against the same plugins.json at the same time.
 * Both child processes read the same "counter" value, sleep while holding PluginsConfigStore's
 * flock(), then write back current+1. Without the lock covering the whole cycle, both would
 * read 0 and write 1 — a lost update.
 *
 * The lock acquire is non-blocking with only a short bounded retry (issue #340): a writer that
 * loses the race outright no longer blocks until the holder releases it, it fails fast with
 * {@see \App\Service\Plugin\Exception\PluginsConfigStoreLockedException}. increment-counter.php
 * retries on that exception at its own pace, so this still asserts the same "no lost updates"
 * invariant — the retry now happens one layer up, in the caller, instead of inside the flock()
 * call itself.
 */
final class PluginsConfigStoreConcurrencyTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-plugins-race-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testConcurrentWritersDoNotLoseUpdates(): void
    {
        $php = (new PhpExecutableFinder())->find();
        $this->assertNotFalse($php, 'PHP CLI binary not found.');

        $script = __DIR__.'/../../../Fixtures/Plugin/increment-counter.php';
        $pluginId = 'animedb-shikimori';
        $sleepMicroseconds = '200000';

        $first = new Process([$php, $script, $this->path, $pluginId, $sleepMicroseconds]);
        $second = new Process([$php, $script, $this->path, $pluginId, $sleepMicroseconds]);

        $first->start();
        $second->start();

        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

        $store = new PluginsConfigStore($this->path);
        $this->assertSame(2, $store->getPluginSettings(new PluginId($pluginId))['counter']);
    }
}
