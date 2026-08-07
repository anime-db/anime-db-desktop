<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\PluginsConfigStoreException;
use App\Service\Plugin\Exception\PluginsConfigStoreLockedException;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;

final class PluginsConfigStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-plugins-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testGetPluginSettingsReturnsEmptyArrayWhenFileIsMissing(): void
    {
        $store = new PluginsConfigStore($this->path);

        $this->assertSame([], $store->getPluginSettings(new PluginId('animedb-shikimori')));
    }

    public function testGetPluginSettingsReturnsEmptyArrayWhenPluginIsUnknown(): void
    {
        file_put_contents($this->path, json_encode(['animedb-shikimori' => ['token' => 'abc']]));

        $store = new PluginsConfigStore($this->path);

        $this->assertSame([], $store->getPluginSettings(new PluginId('animedb-anilist')));
    }

    public function testGetPluginSettingsReturnsEmptyArrayWhenFileIsNotValidJson(): void
    {
        file_put_contents($this->path, '{not json');

        $store = new PluginsConfigStore($this->path);

        $this->assertSame([], $store->getPluginSettings(new PluginId('animedb-shikimori')));
    }

    public function testGetPluginSettingsReadsExistingSettings(): void
    {
        file_put_contents($this->path, json_encode(['animedb-shikimori' => ['refreshToken' => 'abc']]));

        $store = new PluginsConfigStore($this->path);

        $this->assertSame(['refreshToken' => 'abc'], $store->getPluginSettings(new PluginId('animedb-shikimori')));
    }

    public function testUpdatePluginSettingsCreatesFileWhenMissing(): void
    {
        $store = new PluginsConfigStore($this->path);
        $pluginId = new PluginId('animedb-shikimori');

        $store->updatePluginSettings($pluginId, static fn (array $settings): array => ['refreshToken' => 'new-token']);

        $this->assertSame(['refreshToken' => 'new-token'], $store->getPluginSettings($pluginId));
    }

    public function testUpdatePluginSettingsPassesCurrentSettingsToModifier(): void
    {
        $pluginId = new PluginId('animedb-shikimori');
        file_put_contents($this->path, json_encode([(string) $pluginId => ['refreshToken' => 'old']]));

        $store = new PluginsConfigStore($this->path);
        $store->updatePluginSettings($pluginId, static function (array $settings): array {
            $settings['featureFlags'] = ['widgetX' => true];

            return $settings;
        });

        $this->assertSame(
            ['refreshToken' => 'old', 'featureFlags' => ['widgetX' => true]],
            $store->getPluginSettings($pluginId),
        );
    }

    public function testUpdatePluginSettingsDoesNotTouchOtherPlugins(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['refreshToken' => 'shiki-token'],
            'animedb-anilist' => ['refreshToken' => 'anilist-token'],
        ]));

        $store = new PluginsConfigStore($this->path);
        $store->updatePluginSettings(
            new PluginId('animedb-shikimori'),
            static fn (array $settings): array => ['refreshToken' => 'rotated-token'],
        );

        $this->assertSame(
            ['refreshToken' => 'rotated-token'],
            $store->getPluginSettings(new PluginId('animedb-shikimori')),
        );
        $this->assertSame(
            ['refreshToken' => 'anilist-token'],
            $store->getPluginSettings(new PluginId('animedb-anilist')),
        );
    }

    public function testUpdatePluginSettingsCreatesMissingParentDirectory(): void
    {
        $path = sys_get_temp_dir().'/anime-plugins-test-'.uniqid().'/nested/plugins.json';
        $store = new PluginsConfigStore($path);
        $pluginId = new PluginId('animedb-shikimori');

        $store->updatePluginSettings($pluginId, static fn (array $settings): array => ['refreshToken' => 'abc']);

        $this->assertSame(['refreshToken' => 'abc'], $store->getPluginSettings($pluginId));

        unlink($path);
        unlink($path.'.lock');
        rmdir(\dirname($path));
        rmdir(\dirname($path, 2));
    }

    public function testUpdatePluginSettingsLeavesNoTempFileBehind(): void
    {
        $store = new PluginsConfigStore($this->path);
        $store->updatePluginSettings(
            new PluginId('animedb-shikimori'),
            static fn (array $settings): array => ['refreshToken' => 'abc'],
        );

        $this->assertFileDoesNotExist($this->path.'.tmp');
    }

    public function testUpdatePluginSettingsThrowsAndKeepsOriginalFileWhenSettingsAreNotEncodableAsJson(): void
    {
        file_put_contents($this->path, json_encode(['animedb-shikimori' => ['refreshToken' => 'old']]));

        $store = new PluginsConfigStore($this->path);

        $this->expectException(PluginsConfigStoreException::class);

        try {
            $store->updatePluginSettings(
                new PluginId('animedb-shikimori'),
                // "\xB1\x31" is not valid UTF-8, so json_encode() fails for it.
                static fn (array $settings): array => ['refreshToken' => "\xB1\x31"],
            );
        } finally {
            $this->assertSame(
                ['refreshToken' => 'old'],
                $store->getPluginSettings(new PluginId('animedb-shikimori')),
            );
        }
    }

    public function testGetSettingsStorePayloadReturnsEmptyArrayWhenNothingWasStoredYet(): void
    {
        $store = new PluginsConfigStore($this->path);

        $this->assertSame([], $store->getSettingsStorePayload(new PluginId('animedb-shikimori')));
    }

    public function testGetSettingsStorePayloadReadsTheSettingsSubsectionWrittenViaUpdatePluginSettings(): void
    {
        $store = new PluginsConfigStore($this->path);
        $pluginId = new PluginId('animedb-shikimori');

        $store->updatePluginSettings($pluginId, static fn (): array => ['settings' => ['refreshToken' => 'abc']]);

        $this->assertSame(['refreshToken' => 'abc'], $store->getSettingsStorePayload($pluginId));
    }

    public function testPurgeSettingsStorePayloadRemovesTheSettingsSubsectionOnly(): void
    {
        $store = new PluginsConfigStore($this->path);
        $pluginId = new PluginId('animedb-shikimori');
        $store->updatePluginSettings($pluginId, static fn (): array => ['enabled' => true, 'settings' => ['refreshToken' => 'abc']]);

        $store->purgeSettingsStorePayload($pluginId);

        $this->assertSame([], $store->getSettingsStorePayload($pluginId));
        $this->assertSame(['enabled' => true], $store->getPluginSettings($pluginId));
    }

    public function testPurgeSettingsStorePayloadIsANoopWhenNothingWasStored(): void
    {
        $store = new PluginsConfigStore($this->path);
        $pluginId = new PluginId('animedb-shikimori');

        $store->purgeSettingsStorePayload($pluginId);

        $this->assertSame([], $store->getPluginSettings($pluginId));
    }

    public function testUpdatePluginSettingsThrowsWhenTempFileCannotBeWritten(): void
    {
        file_put_contents($this->path, json_encode(['animedb-shikimori' => ['refreshToken' => 'old']]));
        // Pre-create the temp path as a directory so file_put_contents() cannot write to it.
        mkdir($this->path.'.tmp');

        $store = new PluginsConfigStore($this->path);

        $this->expectException(PluginsConfigStoreException::class);

        // file_put_contents() also emits a PHP warning for this expected failure; silence it so
        // it doesn't pollute test output.
        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            $store->updatePluginSettings(
                new PluginId('animedb-shikimori'),
                static fn (array $settings): array => ['refreshToken' => 'new'],
            );
        } finally {
            restore_error_handler();
            $this->assertSame(
                ['refreshToken' => 'old'],
                $store->getPluginSettings(new PluginId('animedb-shikimori')),
            );
            rmdir($this->path.'.tmp');
        }
    }

    /**
     * The lock acquire is non-blocking with a short bounded retry (issue #340): a writer that
     * cannot claim the lock because another one already holds it must fail fast with
     * {@see PluginsConfigStoreLockedException}, not hang waiting for the holder to release it.
     */
    public function testUpdatePluginSettingsFailsFastInsteadOfHangingWhenAnotherWriterHoldsTheLock(): void
    {
        $store = new PluginsConfigStore($this->path);
        $pluginId = new PluginId('animedb-shikimori');

        $lockHandle = fopen($this->path.'.lock', 'c');
        $this->assertNotFalse($lockHandle);
        $this->assertTrue(flock($lockHandle, \LOCK_EX));

        $start = microtime(true);

        try {
            $this->expectException(PluginsConfigStoreLockedException::class);
            $store->updatePluginSettings($pluginId, static fn (array $settings): array => $settings);
        } finally {
            // Well under any reasonable timeout: this asserts the call failed fast rather than
            // blocking on the lock this test process itself is still holding.
            $this->assertLessThan(1.0, microtime(true) - $start);
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }
}
