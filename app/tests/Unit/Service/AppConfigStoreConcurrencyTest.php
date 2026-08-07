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

namespace App\Tests\Unit\Service;

use App\Service\AppConfigStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Reproduces the exact race issue #342 calls out: two writers (e.g. the settings page saving
 * locale and the proxy page saving its settings) doing read -> modify -> write against the same
 * config.json at the same time, each touching a different top-level key. Both child processes
 * read the same starting config, sleep while holding AppConfigStore's flock(), then write their
 * own key back. Without the lock covering the whole cycle, the second rename() would silently
 * discard the first writer's key — a lost update.
 */
final class AppConfigStoreConcurrencyTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-config-race-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testConcurrentWritersOfDifferentKeysDoNotLoseUpdates(): void
    {
        $php = (new PhpExecutableFinder())->find();
        $this->assertNotFalse($php, 'PHP CLI binary not found.');

        $script = __DIR__.'/../../Fixtures/set-config-key.php';
        $sleepMicroseconds = '200000';

        $first = new Process([$php, $script, $this->path, 'locale', 'ru', $sleepMicroseconds]);
        $second = new Process([$php, $script, $this->path, 'proxy', 'manual', $sleepMicroseconds]);

        $first->start();
        $second->start();

        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

        $store = new AppConfigStore($this->path);
        $config = $store->read();

        $this->assertSame('ru', $config['locale']);
        $this->assertSame('manual', $config['proxy']);
    }
}
