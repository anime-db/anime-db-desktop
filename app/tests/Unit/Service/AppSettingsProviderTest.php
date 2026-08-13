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

namespace App\Tests\Unit\Service;

use App\Entity\Enum\PaginationMode;
use App\Entity\ValueObject\PluginId;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use PHPUnit\Framework\TestCase;

final class AppSettingsProviderTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-config-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testDefaultsToInfiniteScrollWhenFileIsMissing(): void
    {
        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertSame(PaginationMode::InfiniteScroll, $provider->getPaginationMode());
    }

    public function testDefaultsToInfiniteScrollWhenKeyIsMissing(): void
    {
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertSame(PaginationMode::InfiniteScroll, $provider->getPaginationMode());
    }

    public function testDefaultsToInfiniteScrollWhenValueIsNotRecognized(): void
    {
        file_put_contents($this->configPath, json_encode(['paginationMode' => 'bogus']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertSame(PaginationMode::InfiniteScroll, $provider->getPaginationMode());
    }

    public function testDefaultsToInfiniteScrollWhenFileIsNotValidJson(): void
    {
        file_put_contents($this->configPath, '{not json');

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertSame(PaginationMode::InfiniteScroll, $provider->getPaginationMode());
    }

    public function testReadsClassicModeFromConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['paginationMode' => 'classic']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertSame(PaginationMode::Classic, $provider->getPaginationMode());
    }

    public function testGetLocaleReturnsNullWhenFileIsMissing(): void
    {
        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertNull($provider->getLocale());
    }

    public function testGetLocaleReadsValueFromConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['locale' => 'ru']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertSame('ru', $provider->getLocale());
    }

    public function testSetLocaleCreatesConfigFileWhenMissing(): void
    {
        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $provider->setLocale('en');

        $this->assertSame('en', $provider->getLocale());
    }

    public function testSetLocaleOverwritesOnlyTheLocaleKey(): void
    {
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc', 'locale' => 'en']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));
        $provider->setLocale('ru');

        $data = json_decode((string) file_get_contents($this->configPath), true);

        $this->assertSame('abc', $data['appSecret']);
        $this->assertSame('ru', $data['locale']);
    }

    public function testSetLocaleCreatesMissingParentDirectory(): void
    {
        $configPath = sys_get_temp_dir().'/anime-config-test-'.uniqid().'/nested/config.json';
        $provider = new AppSettingsProvider(new AppConfigStore($configPath));

        $provider->setLocale('ru');

        $this->assertSame('ru', $provider->getLocale());

        unlink($configPath);
        unlink($configPath.'.lock');
        rmdir(\dirname($configPath));
        rmdir(\dirname($configPath, 2));
    }

    public function testGetDefaultSearchPluginIdReturnsNullWhenFileIsMissing(): void
    {
        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertNull($provider->getDefaultSearchPluginId());
    }

    public function testGetDefaultSearchPluginIdReturnsNullWhenValueIsMalformed(): void
    {
        file_put_contents($this->configPath, json_encode(['defaultSearchPluginId' => 'not_a_valid_id']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertNull($provider->getDefaultSearchPluginId());
    }

    public function testGetDefaultSearchPluginIdReadsValueFromConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['defaultSearchPluginId' => 'animedb-shikimori']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertSame('animedb-shikimori', (string) $provider->getDefaultSearchPluginId());
    }

    public function testSetDefaultSearchPluginIdOverwritesOnlyThatKey(): void
    {
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));
        $provider->setDefaultSearchPluginId(new PluginId('animedb-shikimori'));

        $data = json_decode((string) file_get_contents($this->configPath), true);

        $this->assertSame('abc', $data['appSecret']);
        $this->assertSame('animedb-shikimori', $data['defaultSearchPluginId']);
    }

    public function testSetDefaultSearchPluginIdWithNullClearsTheSetting(): void
    {
        file_put_contents($this->configPath, json_encode(['defaultSearchPluginId' => 'animedb-shikimori']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));
        $provider->setDefaultSearchPluginId(null);

        $this->assertNull($provider->getDefaultSearchPluginId());
    }

    public function testWriteConfigLeavesNoTemporaryFileBehind(): void
    {
        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));
        $provider->setLocale('ru');

        $this->assertFileDoesNotExist($this->configPath.'.tmp');
    }

    public function testWriteConfigPreservesKeysWrittenByAnotherLayerConcurrently(): void
    {
        // Simulates native/config.js (another layer) having already written appSecret before
        // this process reads-modifies-writes locale, i.e. the read-modify-write cycle sees
        // the other layer's key rather than clobbering it.
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));
        $provider->setLocale('ru');
        $provider->setDefaultSearchPluginId(new PluginId('animedb-shikimori'));

        $data = json_decode((string) file_get_contents($this->configPath), true);

        $this->assertSame('abc', $data['appSecret']);
        $this->assertSame('ru', $data['locale']);
        $this->assertSame('animedb-shikimori', $data['defaultSearchPluginId']);
    }

    public function testGetDownloadsRootDefaultsToUserprofileDownloadsWhenFileIsMissing(): void
    {
        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $expected = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME') ?: sys_get_temp_dir()), '\\/').\DIRECTORY_SEPARATOR.'Downloads';
        $this->assertSame($expected, $provider->getDownloadsRoot());
    }

    public function testGetDownloadsRootReadsConfiguredValue(): void
    {
        file_put_contents($this->configPath, json_encode(['downloadsRoot' => 'D:\\Anime\\Downloads']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertSame('D:\\Anime\\Downloads', $provider->getDownloadsRoot());
    }

    public function testSetDownloadsRootOverwritesOnlyThatKey(): void
    {
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));
        $provider->setDownloadsRoot('D:\\Anime\\Downloads');

        $data = json_decode((string) file_get_contents($this->configPath), true);

        $this->assertSame('abc', $data['appSecret']);
        $this->assertSame('D:\\Anime\\Downloads', $data['downloadsRoot']);
    }

    public function testWriteConfigThrowsAndLeavesValidFileIntactWhenTemporaryWriteFails(): void
    {
        // A directory this process owns (unlike sys_get_temp_dir() itself, which is typically
        // root-owned with the sticky bit set, so chmod on it would silently no-op).
        $directory = sys_get_temp_dir().'/anime-config-test-dir-'.uniqid();
        mkdir($directory);
        $configPath = $directory.'/config.json';
        file_put_contents($configPath, json_encode(['appSecret' => 'abc']));

        // Strip write permission from the directory so the temporary file can never be created,
        // simulating a full disk / permission failure partway through the write.
        chmod($directory, 0500);

        $provider = new AppSettingsProvider(new AppConfigStore($configPath));

        // Opening the lock file (also inside $directory) also emits a PHP warning for this
        // expected failure; silence it so it doesn't pollute test output.
        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            $provider->setLocale('ru');
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (\RuntimeException) {
            // expected
        } finally {
            restore_error_handler();
            chmod($directory, 0755);
        }

        $data = json_decode((string) file_get_contents($configPath), true);
        $this->assertSame('abc', $data['appSecret']);
        $this->assertArrayNotHasKey('locale', $data);

        unlink($configPath);
        rmdir($directory);
    }

    public function testGetIncomingConnectionsAllowedDefaultsToFalseWhenFileIsMissing(): void
    {
        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertFalse($provider->getIncomingConnectionsAllowed());
    }

    public function testGetIncomingConnectionsAllowedDefaultsToFalseWhenValueIsMalformed(): void
    {
        file_put_contents($this->configPath, json_encode(['incomingConnectionsAllowed' => 'yes']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertFalse($provider->getIncomingConnectionsAllowed());
    }

    public function testGetIncomingConnectionsAllowedReadsConfiguredValue(): void
    {
        file_put_contents($this->configPath, json_encode(['incomingConnectionsAllowed' => true]));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));

        $this->assertTrue($provider->getIncomingConnectionsAllowed());
    }

    public function testSetIncomingConnectionsAllowedOverwritesOnlyThatKey(): void
    {
        file_put_contents($this->configPath, json_encode(['appSecret' => 'abc']));

        $provider = new AppSettingsProvider(new AppConfigStore($this->configPath));
        $provider->setIncomingConnectionsAllowed(true);

        $data = json_decode((string) file_get_contents($this->configPath), true);

        $this->assertSame('abc', $data['appSecret']);
        $this->assertTrue($data['incomingConnectionsAllowed']);
    }
}
