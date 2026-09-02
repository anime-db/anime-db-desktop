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

namespace App\Tests\Unit\Command;

use App\Command\PluginReconcileCommand;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Support\TemporaryDirectories;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PluginReconcileCommandTest extends TestCase
{
    use TemporaryDirectories;

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeTemporaryDirectories();
    }

    public function testRebuildsTheIndexFromPluginDirectoriesAndSucceeds(): void
    {
        $pluginsDir = $this->createTemporaryDirectory('anime-plugin-reconcile-command-test-');
        $this->writeManifest($pluginsDir, 'animedb-shikimori');

        $registry = new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());

        $tester = new CommandTester(new PluginReconcileCommand($registry));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Reconciled 1 installed plugin(s).', $tester->getDisplay());
        $this->assertTrue($registry->has(new PluginId('animedb-shikimori')));
    }

    public function testDropsAPluginWithAManifestInvalidUnderTheCurrentContractAndKeepsTheRest(): void
    {
        $pluginsDir = $this->createTemporaryDirectory('anime-plugin-reconcile-command-test-');
        $this->writeManifest($pluginsDir, 'animedb-shikimori');

        $brokenDir = $pluginsDir.'/animedb-broken';
        mkdir($brokenDir, recursive: true);
        file_put_contents($brokenDir.'/manifest.json', '{not valid json');

        $registry = new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());

        $tester = new CommandTester(new PluginReconcileCommand($registry));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue($registry->has(new PluginId('animedb-shikimori')));
        $this->assertFalse($registry->has(new PluginId('animedb-broken')));
    }

    public function testIndexFileExistsAfterReconcileInsteadOfBeingDeleted(): void
    {
        $pluginsDir = $this->createTemporaryDirectory('anime-plugin-reconcile-command-test-');
        $this->writeManifest($pluginsDir, 'animedb-shikimori');

        $registry = new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());

        $tester = new CommandTester(new PluginReconcileCommand($registry));
        $tester->execute([]);

        $this->assertFileExists($pluginsDir.'/installed-plugins.php');
    }

    private function writeManifest(string $pluginsDir, string $pluginId): void
    {
        $dir = $pluginsDir.'/'.$pluginId;
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
