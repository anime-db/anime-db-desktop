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
use App\Service\Plugin\PluginDisplayName;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

final class PluginDisplayNameTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-display-name-'.uniqid();
        mkdir($this->pluginsDir.'/acme-list', recursive: true);
        file_put_contents($this->pluginsDir.'/acme-list/manifest.json', (string) json_encode([
            'id' => 'acme-list',
            'name' => 'Acme List',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['widget' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->pluginsDir);
    }

    private function displayName(): PluginDisplayName
    {
        $registry = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->pluginsDir.'/plugins.json'), new NullLogger());
        $registry->reconcile();

        return new PluginDisplayName($registry);
    }

    public function testInstalledPluginIsNamedByItsManifest(): void
    {
        $this->assertSame('Acme List', $this->displayName()->name('acme-list'));
    }

    public function testNotInstalledPluginFallsBackToItsId(): void
    {
        $this->assertSame('gone-plugin', $this->displayName()->name('gone-plugin'));
    }

    public function testInvalidPluginIdFallsBackToTheRawValue(): void
    {
        $this->assertSame('Not A Valid Id!', $this->displayName()->name('Not A Valid Id!'));
    }
}
