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

use App\Command\PluginDeactivateCommand;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginRemover;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PluginDeactivateCommandTest extends TestCase
{
    public function testRemovesTheInstalledPluginDirectoryAndSucceeds(): void
    {
        $rootDir = sys_get_temp_dir().'/anime-plugin-deactivate-command-test-'.uniqid();
        $pluginsDir = $rootDir.'/plugins';
        $pluginDir = $pluginsDir.'/animedb-shikimori';
        mkdir($pluginDir, recursive: true);
        file_put_contents($pluginDir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));

        $registry = new InstalledPluginsRegistry($pluginsDir, new PluginsConfigStore($pluginsDir.'/plugins.json'), new NullLogger());
        $registry->reconcile();

        $command = new PluginDeactivateCommand(new PluginRemover($registry));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['pluginId' => 'animedb-shikimori']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertDirectoryDoesNotExist($pluginDir);
        $this->assertFalse($registry->has(new PluginId('animedb-shikimori')));
    }
}
