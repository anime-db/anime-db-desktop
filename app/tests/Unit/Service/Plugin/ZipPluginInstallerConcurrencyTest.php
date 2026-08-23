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
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Reproduces issue #420 defect B: two concurrent installs of the *same* plugin id. Before
 * {@see \App\Service\Plugin\ZipPluginInstaller::install()} ran under
 * {@see InstalledPluginsRegistry::synchronized()}, both processes could pass the
 * `$registry->has($pluginId) || is_dir($targetDir)` collision check while the target directory
 * didn't exist yet, both `move()` into place — with the second `rename()` failing since the
 * target now exists — and the loser's `catch` block would then delete the directory the winner
 * had *already successfully installed*, even though the winner had already been told "installed".
 *
 * With the lock in place, the two installs fully serialize: the second one starts only once the
 * first has entirely finished, sees the collision cleanly, and never touches the winner's
 * directory at all.
 */
final class ZipPluginInstallerConcurrencyTest extends TestCase
{
    private const CORE_VERSION = '2.5.0';
    private const PLUGIN_ID = 'animedb-shikimori';

    private string $rootDir;
    private string $pluginsDir;
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-zip-installer-race-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        mkdir($this->pluginsDir, recursive: true);

        $this->fixturesDir = sys_get_temp_dir().'/anime-zip-installer-race-fixtures-'.uniqid();
        mkdir($this->fixturesDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
        $this->removeDirectory($this->fixturesDir);
    }

    #[Group('runtime-parity')]
    public function testConcurrentInstallsOfTheSamePluginIdDoNotDeleteTheWinner(): void
    {
        $php = (new PhpExecutableFinder())->find();
        $this->assertNotFalse($php, 'PHP CLI binary not found.');

        $zipPathA = $this->createZip('1.0.0-a');
        $zipPathB = $this->createZip('1.0.0-b');
        $script = __DIR__.'/../../../Fixtures/Plugin/install-plugin.php';
        $resultPathA = $this->fixturesDir.'/result-a.txt';
        $resultPathB = $this->fixturesDir.'/result-b.txt';

        $first = new Process([$php, $script, $this->pluginsDir, self::CORE_VERSION, $zipPathA, $resultPathA]);
        $second = new Process([$php, $script, $this->pluginsDir, self::CORE_VERSION, $zipPathB, $resultPathB]);

        $first->start();
        $second->start();

        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());

        $resultA = file_get_contents($resultPathA);
        $resultB = file_get_contents($resultPathB);
        $this->assertNotFalse($resultA);
        $this->assertNotFalse($resultB);

        // Exactly one process installs the plugin and the other observes the collision — the
        // lock fully serializes them, so there is no outcome where both "win" or both "lose".
        $outcomes = [$resultA, $resultB];
        sort($outcomes);
        $this->assertMatchesRegularExpression('/^already-installed$/', $outcomes[0]);
        $this->assertMatchesRegularExpression('/^installed:1\.0\.0-[ab]$/', $outcomes[1]);

        // The winner's directory must still be exactly what it installed — never deleted by the
        // loser's rollback.
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $installed = $registry->get(new PluginId(self::PLUGIN_ID));

        $this->assertNotNull($installed);
        $this->assertSame($outcomes[1], 'installed:'.$installed->manifest->version);
        $this->assertDirectoryExists($this->pluginsDir.'/'.self::PLUGIN_ID);
        $this->assertFileExists($this->pluginsDir.'/'.self::PLUGIN_ID.'/manifest.json');
    }

    private function createZip(string $version): string
    {
        $zipPath = $this->fixturesDir.'/'.uniqid('plugin-', true).'.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', (string) json_encode([
            'id' => self::PLUGIN_ID,
            'name' => 'Shikimori',
            'version' => $version,
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        $zip->close();

        return $zipPath;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

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
