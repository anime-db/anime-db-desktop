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

use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Reproduces the exact race issue #420 defect A calls out: two concurrent reconcile() calls
 * writing `installed-plugins.php` at the same time. Before {@see InstalledPluginsRegistry::synchronized()}
 * existed, both writers raced to the same fixed `installed-plugins.php.tmp` name — an interleaved
 * write there could publish a syntactically broken PHP file, and readIndex()'s `require` on it is
 * an uncaught ParseError that would crash every request touching the registry, not just the one
 * that lost the race.
 *
 * Both child processes reconcile() the same, already-populated $pluginsDir in a tight loop with no
 * synchronization of their own — exercising InstalledPluginsRegistry's own lock is the entire
 * point, unlike {@see PluginsConfigStoreConcurrencyTest}'s counter
 * script, which has to coordinate retries itself because that store's lock is non-blocking.
 */
final class InstalledPluginsRegistryConcurrencyTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-installed-plugins-race-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);

        foreach (['animedb-shikimori', 'animedb-anilist', 'animedb-onboarding'] as $pluginId) {
            $dir = $this->pluginsDir.'/'.$pluginId;
            mkdir($dir, recursive: true);
            file_put_contents($dir.'/manifest.json', (string) json_encode([
                'id' => $pluginId,
                'name' => ucfirst($pluginId),
                'version' => '1.0.0',
                'type' => 'integration',
                'features' => ['filler' => true],
                'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
            ]));
        }
    }

    protected function tearDown(): void
    {
        $entries = scandir($this->pluginsDir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->pluginsDir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($this->pluginsDir);
    }

    #[Group('runtime-parity')]
    public function testConcurrentReconcilesDoNotCorruptTheIndex(): void
    {
        $php = (new PhpExecutableFinder())->find();
        $this->assertNotFalse($php, 'PHP CLI binary not found.');

        $script = __DIR__.'/../../../Fixtures/Plugin/reconcile-loop.php';
        $iterations = '150';

        $first = new Process([$php, $script, $this->pluginsDir, $iterations]);
        $second = new Process([$php, $script, $this->pluginsDir, $iterations]);

        $first->start();
        $second->start();

        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

        // readIndex() (via all()) requires installed-plugins.php — a ParseError here means the
        // race above published a corrupted index despite the lock.
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );

        $ids = array_map(static fn ($plugin): string => (string) $plugin->id, $registry->all());
        sort($ids);

        $this->assertSame(['animedb-anilist', 'animedb-onboarding', 'animedb-shikimori'], $ids);
    }

    private function removeDirectory(string $dir): void
    {
        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
