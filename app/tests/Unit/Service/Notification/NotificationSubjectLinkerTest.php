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

namespace App\Tests\Unit\Service\Notification;

use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use App\Service\Notification\NotificationSubjectLinker;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsPageRegistry;
use App\Tests\Support\TemporaryDirectories;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NotificationSubjectLinkerTest extends TestCase
{
    use TemporaryDirectories;

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();
    }

    private function linker(bool $enabled = true, bool $withPage = true): NotificationSubjectLinker
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE anime (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('INSERT INTO anime (id) VALUES (7)');
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(static fn (string $route, array $params): string => $route === 'settings_plugin_page' ? '/settings/plugins/'.$params['pluginId'] : '/anime/'.$params['id']);

        $dir = $this->createTemporaryDirectory('linker');
        mkdir($dir.'/animedb-mal');
        file_put_contents($dir.'/animedb-mal/manifest.json', (string) json_encode([
            'id' => 'animedb-mal',
            'name' => 'MAL',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['widget' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        if (!$enabled) {
            file_put_contents($dir.'/plugins.json', (string) json_encode(['animedb-mal' => ['enabled' => false]]));
        }
        $plugins = new InstalledPluginsRegistry($dir, new PluginsConfigStore($dir.'/plugins.json'), new NullLogger());
        $plugins->reconcile();
        $pages = $withPage ? ['animedb-mal' => fn (): SettingsPageInterface => $this->createStub(SettingsPageInterface::class)] : [];

        return new NotificationSubjectLinker($generator, $connection, new SettingsPageRegistry($plugins, new ServiceLocator($pages)));
    }

    public function testExistingSubjectHasALink(): void
    {
        $this->assertSame('/anime/7', $this->linker()->urlFor('anime', '7'));
    }

    public function testNoSubjectVanishedSubjectAndUnknownTypeHaveNoLink(): void
    {
        $linker = $this->linker();

        $this->assertNull($linker->urlFor(null, null));
        $this->assertNull($linker->urlFor('anime', '8'));
        $this->assertNull($linker->urlFor('unknown', '7'));
        $this->assertNull($linker->urlFor('anime', 'x'));
    }

    public function testInstalledPluginHasALinkAndARemovedOrMalformedOneHasNot(): void
    {
        $linker = $this->linker();

        $this->assertSame('/settings/plugins/animedb-mal', $linker->urlFor('plugin', 'animedb-mal'));
        $this->assertNull($linker->urlFor('plugin', 'animedb-gone'));
        $this->assertNull($linker->urlFor('plugin', 'Not A Plugin'));
    }

    public function testInstalledPluginWithoutAReachableSettingsPageHasNoLink(): void
    {
        $this->assertNull($this->linker(enabled: false)->urlFor('plugin', 'animedb-mal'));
        $this->assertNull($this->linker(withPage: false)->urlFor('plugin', 'animedb-mal'));
    }
}
