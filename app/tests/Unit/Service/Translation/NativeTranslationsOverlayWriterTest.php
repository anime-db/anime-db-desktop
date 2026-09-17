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

namespace App\Tests\Unit\Service\Translation;

use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Translation\Exception\NativeTranslationsOverlayException;
use App\Service\Translation\NativeTranslationsOverlayWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Exercises {@see NativeTranslationsOverlayWriter} directly against a real
 * {@see InstalledPluginsRegistry} reading a real temporary plugins directory — never through
 * {@see InstalledPluginsRegistry::reconcile()} (that wiring, and the safe-mode guarantee, is
 * {@see \App\Tests\Unit\Service\Plugin\InstalledPluginsRegistryOverlayIntegrationTest}'s job).
 */
final class NativeTranslationsOverlayWriterTest extends TestCase
{
    private string $rootDir;
    private string $pluginsDir;
    private string $referenceDir;
    private string $overlayDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/anime-native-overlay-writer-test-'.uniqid();
        $this->pluginsDir = $this->rootDir.'/plugins';
        $this->referenceDir = $this->rootDir.'/native-translations';
        $this->overlayDir = $this->rootDir.'/overlay';
        mkdir($this->pluginsDir, recursive: true);
        mkdir($this->referenceDir, recursive: true);

        $this->writeReference(['splash.step_done' => 'Done', 'tray.quit' => 'Quit']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testWriteCreatesFlattenedOverlayFileForEnabledTranslationPlugin(): void
    {
        $this->writeTranslationPlugin('lang-kazakh', ['kk' => ['tray.quit' => 'Шығу']]);

        $this->writer()->write();

        $this->assertSame(['tray.quit' => 'Шығу'], $this->readOverlay('kk'));
    }

    public function testWriteMergesTwoPluginsOnSameLocaleWithHigherIdWinning(): void
    {
        // "lang-a" sorts before "lang-b" — scandir()'s default ascending order, the same order
        // InstalledPluginsRegistry::enabled() yields (see the writer's own class docblock).
        $this->writeTranslationPlugin('lang-a', ['kk' => ['tray.quit' => 'A', 'splash.step_done' => 'Дайын']]);
        $this->writeTranslationPlugin('lang-b', ['kk' => ['tray.quit' => 'B']]);

        $this->writer()->write();

        $this->assertSame(['tray.quit' => 'B', 'splash.step_done' => 'Дайын'], $this->readOverlay('kk'));
    }

    public function testWriteDropsKeyAbsentFromReferenceCatalog(): void
    {
        $this->writeTranslationPlugin('lang-kazakh', ['kk' => [
            'tray.quit' => 'Шығу',
            'plugin.unknown_key' => 'Custom',
        ]]);

        $this->writer()->write();

        $this->assertSame(['tray.quit' => 'Шығу'], $this->readOverlay('kk'));
    }

    public function testWriteDropsNonStringValue(): void
    {
        $this->writeTranslationPlugin('lang-kazakh', ['kk' => [
            'tray.quit' => 'Шығу',
            'splash.step_done' => ['not' => 'a string'],
        ]]);

        $this->writer()->write();

        $this->assertSame(['tray.quit' => 'Шығу'], $this->readOverlay('kk'));
    }

    public function testWriteDropsValueLongerThanOneThousandCharactersAndLogsIt(): void
    {
        $overlong = str_repeat('a', 1001);
        $this->writeTranslationPlugin('lang-kazakh', ['kk' => [
            'tray.quit' => $overlong,
            'splash.step_done' => 'Дайын',
        ]]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('overlong'),
            $this->callback(static fn (array $context): bool => $context['locale'] === 'kk' && $context['key'] === 'tray.quit'),
        );

        $this->writer($logger)->write();

        $this->assertSame(['splash.step_done' => 'Дайын'], $this->readOverlay('kk'));
    }

    public function testWriteAcceptsValueAtExactlyOneThousandCharacters(): void
    {
        $maxLength = str_repeat('a', 1000);
        $this->writeTranslationPlugin('lang-kazakh', ['kk' => ['tray.quit' => $maxLength]]);

        $this->writer()->write();

        $this->assertSame(['tray.quit' => $maxLength], $this->readOverlay('kk'));
    }

    public function testWriteSkipsLocaleWithNoKeysLeftAfterSanitizing(): void
    {
        $this->writeTranslationPlugin('lang-kazakh', ['kk' => ['plugin.unknown_key' => 'Custom']]);

        $this->writer()->write();

        $this->assertFalse(is_file($this->overlayDir.'/kk.json'));
    }

    public function testWriteIgnoresNativeTranslationsOfNonTranslationTypePlugin(): void
    {
        $this->writePlugin('animedb-shikimori', 'integration', ['kk' => ['tray.quit' => 'Шығу']]);

        $this->writer()->write();

        $this->assertFalse(is_file($this->overlayDir.'/kk.json'));
    }

    public function testWriteRemovesLocaleNoLongerCoveredByAnyEnabledTranslationPlugin(): void
    {
        mkdir($this->overlayDir, recursive: true);
        file_put_contents($this->overlayDir.'/stale.json', json_encode(['tray.quit' => 'Old']));

        $this->writer()->write();

        $this->assertFalse(is_file($this->overlayDir.'/stale.json'));
    }

    public function testWriteRemovesAllOverlayFilesWhenNoTranslationPluginIsEnabled(): void
    {
        mkdir($this->overlayDir, recursive: true);
        file_put_contents($this->overlayDir.'/kk.json', json_encode(['tray.quit' => 'Old']));

        $this->writer()->write();

        $this->assertSame([], array_values(array_diff((array) scandir($this->overlayDir), ['.', '..'])));
        // The overlay directory itself stays in place, only its stale contents are cleared.
        $this->assertDirectoryExists($this->overlayDir);
    }

    public function testWriteThrowsAndLeavesExistingOverlayUntouchedWhenReferenceCatalogIsMissing(): void
    {
        unlink($this->referenceDir.'/en.json');
        mkdir($this->overlayDir, recursive: true);
        file_put_contents($this->overlayDir.'/kk.json', json_encode(['tray.quit' => 'Existing']));

        $this->expectException(NativeTranslationsOverlayException::class);

        try {
            $this->writer()->write();
        } finally {
            $this->assertSame(['tray.quit' => 'Existing'], $this->readOverlay('kk'));
        }
    }

    public function testWriteThrowsWhenReferenceCatalogIsInvalidJson(): void
    {
        file_put_contents($this->referenceDir.'/en.json', '{not valid json');

        $this->expectException(NativeTranslationsOverlayException::class);

        $this->writer()->write();
    }

    public function testWriteSkipsUnreadableNativeTranslationsCatalogOfAPluginAndLogsIt(): void
    {
        $this->writeTranslationPlugin('lang-broken', []);
        $nativeDir = $this->pluginsDir.'/lang-broken/translations/native';
        file_put_contents($nativeDir.'/kk.json', '{not valid json');

        $this->writeTranslationPlugin('lang-good', ['ru' => ['tray.quit' => 'Выход']]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('not a valid JSON object'),
            $this->callback(static fn (array $context): bool => $context['pluginId'] === 'lang-broken'),
        );

        $this->writer($logger)->write();

        $this->assertSame(['tray.quit' => 'Выход'], $this->readOverlay('ru'));
        $this->assertFalse(is_file($this->overlayDir.'/kk.json'));
    }

    public function testWriteLeavesNoTemporaryFilesBehind(): void
    {
        $this->writeTranslationPlugin('lang-kazakh', ['kk' => ['tray.quit' => 'Шығу']]);

        $this->writer()->write();

        $this->assertSame([], glob($this->overlayDir.'/*.tmp.*'));
    }

    private function writer(?LoggerInterface $logger = null): NativeTranslationsOverlayWriter
    {
        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        return new NativeTranslationsOverlayWriter(
            $this->referenceDir,
            $this->overlayDir,
            $registry,
            $logger ?? new NullLogger(),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $nativeCatalogsByLocale locale => raw catalog
     */
    private function writeTranslationPlugin(string $pluginId, array $nativeCatalogsByLocale): void
    {
        $this->writePlugin($pluginId, 'translation', $nativeCatalogsByLocale);
    }

    /**
     * @param array<string, array<string, mixed>> $nativeCatalogsByLocale locale => raw catalog
     */
    private function writePlugin(string $pluginId, string $type, array $nativeCatalogsByLocale): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir.'/translations/native', recursive: true);

        $manifest = [
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => $type,
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ];

        if ($type === 'translation') {
            $manifest['locales'] = array_keys($nativeCatalogsByLocale) ?: ['kk'];
        } else {
            $manifest['features'] = ['filler' => true];
        }

        file_put_contents($dir.'/manifest.json', (string) json_encode($manifest));

        foreach ($nativeCatalogsByLocale as $locale => $catalog) {
            file_put_contents($dir.'/translations/native/'.$locale.'.json', (string) json_encode($catalog));
        }
    }

    /**
     * @param array<string, string> $catalog
     */
    private function writeReference(array $catalog): void
    {
        file_put_contents($this->referenceDir.'/en.json', (string) json_encode($catalog));
    }

    /**
     * @return array<string, string>|null
     */
    private function readOverlay(string $locale): ?array
    {
        $path = $this->overlayDir.'/'.$locale.'.json';
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : null;
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
