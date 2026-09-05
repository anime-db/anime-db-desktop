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

use App\Service\AppConfigStore;
use App\Service\Exception\AppConfigStoreException;
use App\Service\Exception\AppConfigStoreLockedException;
use PHPUnit\Framework\TestCase;

final class AppConfigStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-config-store-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testReadReturnsEmptyArrayWhenFileIsMissing(): void
    {
        $store = new AppConfigStore($this->path);

        $this->assertSame([], $store->read());
    }

    public function testReadReturnsEmptyArrayWhenFileIsNotValidJson(): void
    {
        file_put_contents($this->path, '{not json');

        $store = new AppConfigStore($this->path);

        $this->assertSame([], $store->read());
    }

    public function testReadReturnsExistingConfig(): void
    {
        file_put_contents($this->path, json_encode(['locale' => 'ru']));

        $store = new AppConfigStore($this->path);

        $this->assertSame(['locale' => 'ru'], $store->read());
    }

    public function testUpdateCreatesFileWhenMissing(): void
    {
        $store = new AppConfigStore($this->path);

        $store->update(static fn (array $config): array => ['locale' => 'ru']);

        $this->assertSame(['locale' => 'ru'], $store->read());
    }

    public function testUpdatePassesCurrentConfigToModifier(): void
    {
        file_put_contents($this->path, json_encode(['appSecret' => 'abc']));

        $store = new AppConfigStore($this->path);
        $store->update(static function (array $config): array {
            $config['locale'] = 'ru';

            return $config;
        });

        $this->assertSame(['appSecret' => 'abc', 'locale' => 'ru'], $store->read());
    }

    public function testUpdateCreatesMissingParentDirectory(): void
    {
        $path = sys_get_temp_dir().'/anime-config-store-test-'.uniqid().'/nested/config.json';
        $store = new AppConfigStore($path);

        $store->update(static fn (array $config): array => ['locale' => 'ru']);

        $this->assertSame(['locale' => 'ru'], $store->read());

        unlink($path);
        unlink($path.'.lock');
        rmdir(\dirname($path));
        rmdir(\dirname($path, 2));
    }

    public function testUpdateLeavesNoTempFileBehind(): void
    {
        $store = new AppConfigStore($this->path);
        $store->update(static fn (array $config): array => ['locale' => 'ru']);

        $this->assertFileDoesNotExist($this->path.'.tmp');
    }

    public function testUpdateThrowsAndKeepsOriginalFileWhenConfigIsNotEncodableAsJson(): void
    {
        file_put_contents($this->path, json_encode(['locale' => 'ru']));

        $store = new AppConfigStore($this->path);

        $this->expectException(AppConfigStoreException::class);

        try {
            $store->update(
                // "\xB1\x31" is not valid UTF-8, so json_encode() fails for it.
                static fn (array $config): array => ['locale' => "\xB1\x31"],
            );
        } finally {
            $this->assertSame(['locale' => 'ru'], $store->read());
        }
    }

    public function testUpdateThrowsWhenTempFileCannotBeWritten(): void
    {
        file_put_contents($this->path, json_encode(['locale' => 'ru']));
        // Pre-create the temp path as a directory so file_put_contents() cannot write to it.
        mkdir($this->path.'.tmp');

        $store = new AppConfigStore($this->path);

        $this->expectException(AppConfigStoreException::class);

        // file_put_contents() also emits a PHP warning for this expected failure; silence it so
        // it doesn't pollute test output.
        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            $store->update(static fn (array $config): array => ['locale' => 'en']);
        } finally {
            restore_error_handler();
            $this->assertSame(['locale' => 'ru'], $store->read());
            rmdir($this->path.'.tmp');
        }
    }

    public function testUpdateThrowsAndRemovesTempFileWhenRenameFails(): void
    {
        // Pre-create the destination as a directory: rename() cannot replace a directory with a
        // file, the same failure mode the issue reproduced with another process (e.g. antivirus)
        // holding a handle on config.json on Windows (#589).
        mkdir($this->path);

        $store = new AppConfigStore($this->path);

        $this->expectException(AppConfigStoreException::class);

        // rename() also emits a PHP warning for this expected failure; silence it so it doesn't
        // pollute test output.
        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            $store->update(static fn (array $config): array => ['locale' => 'ru']);
        } finally {
            restore_error_handler();
            $this->assertFileDoesNotExist($this->path.'.tmp');
            rmdir($this->path);
        }
    }

    /**
     * The lock acquire is non-blocking with a short bounded retry: a writer that cannot claim
     * the lock because another one already holds it must fail fast with
     * {@see AppConfigStoreLockedException}, not hang waiting for the holder to release it.
     */
    public function testUpdateFailsFastInsteadOfHangingWhenAnotherWriterHoldsTheLock(): void
    {
        $store = new AppConfigStore($this->path);

        $lockHandle = fopen($this->path.'.lock', 'c');
        $this->assertNotFalse($lockHandle);
        $this->assertTrue(flock($lockHandle, \LOCK_EX));

        $start = microtime(true);

        try {
            $this->expectException(AppConfigStoreLockedException::class);
            $store->update(static fn (array $config): array => $config);
        } finally {
            // Well under any reasonable timeout: this asserts the call failed fast rather than
            // blocking on the lock this test process itself is still holding.
            $this->assertLessThan(1.0, microtime(true) - $start);
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }
}
