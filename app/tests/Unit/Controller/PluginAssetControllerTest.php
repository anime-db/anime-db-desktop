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

namespace App\Tests\Unit\Controller;

use App\Controller\PluginAssetController;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginAssetFingerprint;
use App\Service\Plugin\PluginAssetResolver;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PluginAssetControllerTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-plugin-asset-controller-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function writeManifest(string $pluginId, array $overrides = []): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir.'/assets', recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode(array_merge([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ], $overrides)));
    }

    private function createController(?PluginsConfigStore $configStore = null): PluginAssetController
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            $configStore ?? new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        return new PluginAssetController(new PluginAssetResolver($registry));
    }

    private function disabledConfigStore(string $pluginId): PluginsConfigStore
    {
        $path = $this->pluginsDir.'/plugins.json';
        file_put_contents($path, (string) json_encode([$pluginId => ['enabled' => false]]));

        return new PluginsConfigStore($path);
    }

    private function fingerprintOf(string $absolutePath): string
    {
        return PluginAssetFingerprint::forFile($absolutePath);
    }

    public function testServesExistingCssFileWithNosniffAndImmutableCacheHeaders(): void
    {
        $this->writeManifest('animedb-shikimori');
        $cssPath = $this->pluginsDir.'/animedb-shikimori/assets/carousel.css';
        file_put_contents($cssPath, '.carousel { display: flex; }');

        $controller = $this->createController();
        $response = $controller('animedb-shikimori', $this->fingerprintOf($cssPath), 'assets/carousel.css');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('.carousel { display: flex; }', $response->getContent());
        $this->assertSame('text/css', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertTrue($response->headers->getCacheControlDirective('private'));
        $this->assertTrue($response->headers->getCacheControlDirective('immutable'));
        $this->assertSame(31_536_000, $response->getMaxAge());
    }

    public function testServesSvgAsImageSvgXmlRegardlessOfItsContent(): void
    {
        $this->writeManifest('animedb-shikimori');
        $svgPath = $this->pluginsDir.'/animedb-shikimori/assets/logo.svg';
        file_put_contents($svgPath, '<html><body>not actually an svg</body></html>');

        $controller = $this->createController();
        $response = $controller('animedb-shikimori', $this->fingerprintOf($svgPath), 'assets/logo.svg');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
    }

    public function testReturnsNotFoundForAnUppercaseExtensionEvenThoughTheLowercaseFormIsAllowed(): void
    {
        $this->writeManifest('animedb-shikimori');
        $svgPath = $this->pluginsDir.'/animedb-shikimori/assets/Logo.SVG';
        file_put_contents($svgPath, '<svg></svg>');

        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', $this->fingerprintOf($svgPath), 'assets/Logo.SVG');
    }

    public function testReturnsNotFoundForAMalformedPluginId(): void
    {
        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('Not A Valid Id', 'deadbeefdeadbeef', 'assets/carousel.css');
    }

    public function testReturnsNotFoundWhenThePluginDoesNotExist(): void
    {
        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', 'deadbeefdeadbeef', 'assets/carousel.css');
    }

    public function testReturnsNotFoundWhenThePluginIsDisabled(): void
    {
        $this->writeManifest('animedb-shikimori');
        $cssPath = $this->pluginsDir.'/animedb-shikimori/assets/carousel.css';
        file_put_contents($cssPath, '.carousel {}');

        $controller = $this->createController($this->disabledConfigStore('animedb-shikimori'));

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', $this->fingerprintOf($cssPath), 'assets/carousel.css');
    }

    public function testReturnsNotFoundForAPathContainingADotDotSegment(): void
    {
        $this->writeManifest('animedb-shikimori');

        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', 'deadbeefdeadbeef', 'assets/../manifest.json');
    }

    public function testReturnsNotFoundForAnAbsolutePath(): void
    {
        $this->writeManifest('animedb-shikimori');
        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', 'deadbeefdeadbeef', '/etc/passwd');
    }

    public function testReturnsNotFoundForAWindowsDriveAbsolutePath(): void
    {
        $this->writeManifest('animedb-shikimori');
        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', 'deadbeefdeadbeef', 'C:/secret.css');
    }

    public function testReturnsNotFoundForAPathContainingABackslash(): void
    {
        $this->writeManifest('animedb-shikimori');
        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', 'deadbeefdeadbeef', 'assets\\carousel.css');
    }

    public function testReturnsNotFoundForAPathContainingANulByte(): void
    {
        $this->writeManifest('animedb-shikimori');
        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', 'deadbeefdeadbeef', "assets/carousel.css\0.png");
    }

    public function testReturnsNotFoundForAnExtensionOutsideTheAllowList(): void
    {
        $this->writeManifest('animedb-shikimori');
        $phpPath = $this->pluginsDir.'/animedb-shikimori/assets/shell.php';
        file_put_contents($phpPath, '<?php echo "hi"; ');

        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', $this->fingerprintOf($phpPath), 'assets/shell.php');
    }

    public function testReturnsNotFoundForAFileOutsideTheAssetsDirectory(): void
    {
        $this->writeManifest('animedb-shikimori');
        $manifestPath = $this->pluginsDir.'/animedb-shikimori/manifest.json';

        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', $this->fingerprintOf($manifestPath), 'manifest.json');
    }

    public function testReturnsNotFoundOnFingerprintMismatchInsteadOfServingCurrentBytes(): void
    {
        $this->writeManifest('animedb-shikimori');
        $cssPath = $this->pluginsDir.'/animedb-shikimori/assets/carousel.css';
        file_put_contents($cssPath, '.carousel {}');

        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller('animedb-shikimori', '0000000000000000', 'assets/carousel.css');
    }

    public function testServesAFileFromAPluginDirectoryThatIsASymlink(): void
    {
        $realDir = sys_get_temp_dir().'/anime-plugin-asset-real-'.uniqid();
        mkdir($realDir.'/assets', recursive: true);
        file_put_contents($realDir.'/manifest.json', (string) json_encode([
            'id' => 'animedb-shikimori',
            'name' => 'Shikimori',
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
        $cssPath = $realDir.'/assets/carousel.css';
        file_put_contents($cssPath, '.carousel {}');

        symlink($realDir, $this->pluginsDir.'/animedb-shikimori');

        try {
            $controller = $this->createController();
            $response = $controller('animedb-shikimori', $this->fingerprintOf($cssPath), 'assets/carousel.css');

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('.carousel {}', $response->getContent());
        } finally {
            $this->removeDirectory($realDir);
        }
    }

    public function testReturnsNotFoundForAFileReachedThroughASymlinkEscapingTheAssetsDirectory(): void
    {
        $this->writeManifest('animedb-shikimori');

        $outsideSecret = sys_get_temp_dir().'/anime-plugin-asset-outside-secret-'.uniqid().'.css';
        file_put_contents($outsideSecret, '.secret {}');

        $escapeLink = $this->pluginsDir.'/animedb-shikimori/assets/escape.css';
        symlink($outsideSecret, $escapeLink);

        try {
            $controller = $this->createController();

            $this->expectException(NotFoundHttpException::class);
            $controller('animedb-shikimori', $this->fingerprintOf($outsideSecret), 'assets/escape.css');
        } finally {
            unlink($outsideSecret);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }

        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            (is_dir($path) && !is_link($path)) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
