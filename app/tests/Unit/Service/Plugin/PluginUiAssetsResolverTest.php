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
use App\Service\Plugin\PluginAssetResolver;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\PluginUiAssetsResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PluginUiAssetsResolverTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-ui-assets-resolver-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    /**
     * @param list<string> $css
     * @param list<string> $js
     */
    private function writeManifest(string $pluginId, array $css, array $js): void
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
            'ui' => ['css' => $css, 'js' => $js],
        ]));
    }

    private function createResolver(LoggerInterface $logger, bool $reconcile = true): PluginUiAssetsResolver
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            $logger,
        );
        if ($reconcile) {
            $registry->reconcile();
        }

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name, array $params): string => \sprintf(
                '/plugin/%s/asset/%s/%s',
                $params['pluginId'],
                $params['fingerprint'],
                $params['path'],
            ),
        );

        return new PluginUiAssetsResolver(new PluginAssetResolver($registry), $urlGenerator, $logger);
    }

    public function testSkipsAMissingFileAndLogsAWarningWithThePluginIdAndPath(): void
    {
        $this->writeManifest('animedb-shikimori', ['assets/missing.css'], []);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->isString(),
            ['pluginId' => 'animedb-shikimori', 'path' => 'assets/missing.css'],
        );

        $ui = $this->createResolver($logger)->resolve(new PluginId('animedb-shikimori'));

        $this->assertSame(['css' => [], 'js' => []], $ui);
    }

    /**
     * {@see \AnimeDb\PluginContracts\Manifest\ManifestValidator} already rejects a manifest whose
     * declared "ui.js" entry does not end in ".js" (and "ui.css" in ".css"), so a path with a
     * disallowed extension can never reach the resolver via a freshly reconciled manifest.json.
     * The resolver's own {@see PluginAssetResolver::isServableExtension()} check is defense in
     * depth against a persisted index written by an older, looser parser — simulated here the same
     * way {@see InstalledPluginsRegistryTest} does, by rewriting the
     * already-reconciled index entry directly instead of going through reconcile() again.
     */
    public function testSkipsAPathWithAnExtensionOutsideTheAllowListAndLogsAWarning(): void
    {
        $dir = $this->pluginsDir.'/animedb-shikimori';
        mkdir($dir.'/assets', recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        file_put_contents($dir.'/assets/settings.php', '<?php echo "hi"; ');

        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();
        $this->rewriteIndexUi('animedb-shikimori', [], ['assets/settings.php']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->isString(),
            ['pluginId' => 'animedb-shikimori', 'path' => 'assets/settings.php'],
        );

        $ui = $this->createResolver($logger, reconcile: false)->resolve(new PluginId('animedb-shikimori'));

        $this->assertSame(['css' => [], 'js' => []], $ui);
    }

    public function testKeepsTheResolvableEntryWhenAnotherDeclaredEntryIsMissing(): void
    {
        $this->writeManifest('animedb-shikimori', ['assets/missing.css', 'assets/present.css'], []);
        $presentPath = $this->pluginsDir.'/animedb-shikimori/assets/present.css';
        file_put_contents($presentPath, '.carousel {}');

        $logger = $this->createStub(LoggerInterface::class);

        $ui = $this->createResolver($logger)->resolve(new PluginId('animedb-shikimori'));

        $this->assertCount(1, $ui['css']);
        $this->assertStringEndsWith('/assets/present.css', $ui['css'][0]);
    }

    /**
     * @param list<string> $css
     * @param list<string> $js
     */
    private function rewriteIndexUi(string $pluginId, array $css, array $js): void
    {
        $indexPath = $this->pluginsDir.'/installed-plugins.php';
        $entries = require $indexPath;

        $entries[$pluginId]['manifest']['ui'] = ['css' => $css, 'js' => $js];

        file_put_contents($indexPath, "<?php\n\nreturn ".var_export($entries, true).";\n");
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
