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

namespace App\Tests\Unit\Twig;

use App\Controller\PluginAssetController;
use App\Service\Plugin\Exception\PluginAssetNotFoundException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginAssetFingerprint;
use App\Service\Plugin\PluginAssetResolver;
use App\Service\Plugin\PluginsConfigStore;
use App\Twig\PluginAssetExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PluginAssetExtensionTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-asset-extension-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    private function writeManifest(string $pluginId): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir.'/assets', recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    private function createExtension(): PluginAssetExtension
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $params): string => \sprintf(
                '/plugin/%s/asset/%s/%s',
                $params['pluginId'],
                $params['fingerprint'],
                $params['path'],
            ),
        );

        return new PluginAssetExtension(new PluginAssetResolver($registry), $urlGenerator);
    }

    public function testGeneratesAUrlThatThePluginAssetControllerAccepts(): void
    {
        $this->writeManifest('animedb-shikimori');
        $svgPath = $this->pluginsDir.'/animedb-shikimori/assets/logo.svg';
        file_put_contents($svgPath, '<svg></svg>');

        $url = $this->createExtension()->generate('animedb-shikimori', 'assets/logo.svg');
        $fingerprint = PluginAssetFingerprint::forFile($svgPath);

        $this->assertSame('/plugin/animedb-shikimori/asset/'.$fingerprint.'/assets/logo.svg', $url);

        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $controller = new PluginAssetController(new PluginAssetResolver($registry));
        $response = $controller('animedb-shikimori', $fingerprint, 'assets/logo.svg');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<svg></svg>', $response->getContent());
    }

    public function testThrowsForAnUnknownPlugin(): void
    {
        $this->expectException(PluginAssetNotFoundException::class);
        $this->createExtension()->generate('animedb-does-not-exist', 'assets/logo.svg');
    }

    public function testThrowsForAMalformedPluginId(): void
    {
        $this->expectException(PluginAssetNotFoundException::class);
        $this->createExtension()->generate('Not A Valid Id', 'assets/logo.svg');
    }

    public function testThrowsForAMissingFile(): void
    {
        $this->writeManifest('animedb-shikimori');

        $this->expectException(PluginAssetNotFoundException::class);
        $this->createExtension()->generate('animedb-shikimori', 'assets/does-not-exist.svg');
    }

    public function testThrowsForADisabledPlugin(): void
    {
        $this->writeManifest('animedb-shikimori');
        file_put_contents($this->pluginsDir.'/animedb-shikimori/assets/logo.svg', '<svg></svg>');
        file_put_contents($this->pluginsDir.'/plugins.json', (string) json_encode([
            'animedb-shikimori' => ['enabled' => false],
        ]));

        $this->expectException(PluginAssetNotFoundException::class);
        $this->createExtension()->generate('animedb-shikimori', 'assets/logo.svg');
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
