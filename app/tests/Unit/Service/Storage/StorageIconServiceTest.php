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

namespace App\Tests\Unit\Service\Storage;

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Service\Storage\AutorunInfReader;
use App\Service\Storage\StorageIconService;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StorageIconServiceTest extends TestCase
{
    private const ICONS = __DIR__.'/../../../../resources/storage-icons';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/storage-icon-test-'.uniqid();
        mkdir($this->dir, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->dir);
    }

    private function storage(int $id = 5, StorageType $type = StorageType::Folder, string $name = 'Main folder'): Storage
    {
        $storage = new Storage($name, $this->dir, $type);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);

        return $storage;
    }

    private function service(bool $driveRoot = false, ?LoggerInterface $logger = null): StorageIconService
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);

        return new StorageIconService(
            new StorageMarkerService($entityManager),
            new AutorunInfReader(),
            self::ICONS,
            $logger ?? $this->createStub(LoggerInterface::class),
            static fn (): bool => $driveRoot,
        );
    }

    private function marker(int $id = 5, string $extra = ''): void
    {
        file_put_contents($this->dir.'/desktop.ini', "[AnimeDB]\nid=$id\n".$extra);
    }

    private function autorun(string $icon, string $label): string
    {
        return "\xFF\xFE".mb_convert_encoding("[autorun]\r\nicon=$icon\r\nlabel=$label", 'UTF-16LE', 'UTF-8');
    }

    public function testDriveRootWithoutAutorunGetsUtf16Autorun(): void
    {
        $this->marker();

        $this->service(true)->apply($this->storage(name: 'Аниме'));

        $this->assertSame($this->autorun('animedb-folder.ico', 'Аниме'), file_get_contents($this->dir.'/autorun.inf'));
        $this->assertFileEquals(self::ICONS.'/animedb-folder.ico', $this->dir.'/animedb-folder.ico');
        $this->assertStringNotContainsString('ShellClassInfo', (string) file_get_contents($this->dir.'/desktop.ini'));
    }

    public function testDriveRootOwnAutorunFollowsRenameAndTypeChange(): void
    {
        $this->marker();
        $service = $this->service(true);
        $service->apply($this->storage(name: 'Old'));

        $service->apply($this->storage(type: StorageType::External, name: 'New'));

        $this->assertSame($this->autorun('animedb-external.ico', 'New'), file_get_contents($this->dir.'/autorun.inf'));
        $this->assertFileExists($this->dir.'/animedb-external.ico');
        $this->assertFileDoesNotExist($this->dir.'/animedb-folder.ico');
    }

    public function testDriveRootForeignAutorunIsLeftAlone(): void
    {
        $this->marker();
        $foreign = "[autorun]\r\nicon=my.ico\r\nlabel=Mine\r\n";
        file_put_contents($this->dir.'/autorun.inf', $foreign);

        $this->service(true)->apply($this->storage());

        $this->assertSame($foreign, file_get_contents($this->dir.'/autorun.inf'));
        $this->assertFileDoesNotExist($this->dir.'/animedb-folder.ico');
    }

    public function testForeignAutorunNamingOurIconWithoutMarkerMatchIsLeftAlone(): void
    {
        $this->marker(7);
        $ours = $this->autorun('animedb-external.ico', 'Other storage');
        file_put_contents($this->dir.'/autorun.inf', $ours);

        $this->service(true)->apply($this->storage(5));

        $this->assertSame($ours, file_get_contents($this->dir.'/autorun.inf'));
        $this->assertFileDoesNotExist($this->dir.'/animedb-folder.ico');
    }

    public function testFolderGetsIconResourceKeepingEverythingElse(): void
    {
        $this->marker(5, "[Other]\nkey=1\n");

        $this->service()->apply($this->storage());

        $ini = (string) file_get_contents($this->dir.'/desktop.ini');
        $this->assertStringContainsString("[AnimeDB]\nid=5\n", $ini);
        $this->assertStringContainsString("[Other]\nkey=1\n", $ini);
        $this->assertStringContainsString("[.ShellClassInfo]\nIconResource=animedb-folder.ico,0\n", $ini);
        $this->assertFileEquals(self::ICONS.'/animedb-folder.ico', $this->dir.'/animedb-folder.ico');
    }

    public function testFolderKeepsForeignShellClassInfoKeys(): void
    {
        $this->marker(5, "[.ShellClassInfo]\nInfoTip=Hello\n");

        $this->service()->apply($this->storage());

        $ini = (string) file_get_contents($this->dir.'/desktop.ini');
        $this->assertStringContainsString('InfoTip=Hello', $ini);
        $this->assertStringContainsString('IconResource=animedb-folder.ico,0', $ini);
        $this->assertSame(1, substr_count(strtolower($ini), '[.shellclassinfo]'));
    }

    /** @return iterable<string, array{string}> */
    public static function foreignIcons(): iterable
    {
        yield 'foreign IconResource' => ["[.ShellClassInfo]\nIconResource=my.ico,0\n"];
        yield 'foreign IconFile without IconResource' => ["[.ShellClassInfo]\nIconFile=my.ico\nIconIndex=0\n"];
        yield 'other case section and key' => ["[.shellclassinfo]\niconresource=my.ico,0\n"];
        yield 'other case IconFile' => ["[.SHELLCLASSINFO]\nICONFILE=my.ico\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignIcons')]
    public function testFolderForeignIconLeavesFileAndIconUntouched(string $section): void
    {
        $this->marker(5, $section);
        $before = (string) file_get_contents($this->dir.'/desktop.ini');

        $this->service()->apply($this->storage());

        $this->assertSame($before, file_get_contents($this->dir.'/desktop.ini'));
        $this->assertFileDoesNotExist($this->dir.'/animedb-folder.ico');
    }

    public function testOurIconInOtherCaseIsRewrittenInPlace(): void
    {
        $this->marker(5, "[.shellclassinfo]\niconresource=.\\animedb-external.ico,0\n");

        $this->service()->apply($this->storage());

        $ini = (string) file_get_contents($this->dir.'/desktop.ini');
        $this->assertSame(1, substr_count(strtolower($ini), 'iconresource'));
        $this->assertStringContainsString('IconResource=animedb-folder.ico,0', $ini);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function iconValues(): iterable
    {
        yield 'plain' => ['animedb-folder.ico', true];
        yield 'with index' => ['animedb-folder.ico,0', true];
        yield 'quoted' => ['"animedb-folder.ico"', true];
        yield 'quoted with index' => ['"animedb-external.ico",0', true];
        yield 'relative prefix' => ['.\animedb-external.ico', true];
        yield 'upper case' => ['ANIMEDB-FOLDER.ICO,0', true];
        yield 'foreign' => ['my.ico,0', false];
        yield 'other directory' => ['C:\icons\animedb-folder.ico', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('iconValues')]
    public function testIconValueNormalization(string $value, bool $ours): void
    {
        $this->assertSame($ours, StorageIconService::isOurIcon($value));
    }

    public function testAutorunVariantsAreRecognizedAsOurs(): void
    {
        $this->marker();
        file_put_contents($this->dir.'/autorun.inf', "[AutoRun]\r\nIcon=\"animedb-folder.ico\",0\r\nLabel=Old\r\n");

        $this->service(true)->apply($this->storage(name: 'New'));

        $this->assertSame($this->autorun('animedb-folder.ico', 'New'), file_get_contents($this->dir.'/autorun.inf'));
    }

    public function testApplyDoesNothingWhenMarkerNamesAnotherStorage(): void
    {
        $this->marker(7);

        $this->service()->apply($this->storage(5));

        $this->assertFileDoesNotExist($this->dir.'/animedb-folder.ico');
        $this->assertSame("[AnimeDB]\nid=7\n", file_get_contents($this->dir.'/desktop.ini'));
    }

    public function testApplyDoesNothingForNonWritableType(): void
    {
        $this->marker();

        $this->service()->apply($this->storage(type: StorageType::ExternalR));

        $this->assertFileDoesNotExist($this->dir.'/animedb-external.ico');
        $this->assertSame("[AnimeDB]\nid=5\n", file_get_contents($this->dir.'/desktop.ini'));
    }

    public function testTypeChangeSwapsIconFiles(): void
    {
        $this->marker();
        $service = $this->service();
        $service->apply($this->storage());

        $service->apply($this->storage(type: StorageType::External));

        $this->assertFileDoesNotExist($this->dir.'/animedb-folder.ico');
        $this->assertFileExists($this->dir.'/animedb-external.ico');
        $this->assertStringContainsString('IconResource=animedb-external.ico,0', (string) file_get_contents($this->dir.'/desktop.ini'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rootShapes')]
    public function testRepeatedApplyWritesNothing(bool $driveRoot): void
    {
        $this->marker();
        $service = $this->service($driveRoot);
        $service->apply($this->storage());
        $files = glob($this->dir.'/*') ?: [];
        $before = [];
        foreach ($files as $file) {
            touch($file, 1_000_000_000);
            clearstatcache();
            $before[$file] = [filemtime($file), file_get_contents($file)];
        }

        $service->apply($this->storage());
        clearstatcache();

        $this->assertSame($files, glob($this->dir.'/*'));
        foreach ($before as $file => [$mtime, $content]) {
            $this->assertSame($mtime, filemtime($file), $file);
            $this->assertSame($content, file_get_contents($file), $file);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function rootShapes(): iterable
    {
        yield 'drive root' => [true];
        yield 'folder' => [false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rootShapes')]
    public function testRemoveClearsOurFilesAndLeavesNothingBehindAfterForget(bool $driveRoot): void
    {
        $this->marker();
        $service = $this->service($driveRoot);
        $storage = $this->storage();
        $service->apply($storage);

        $service->remove($storage);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        (new StorageMarkerService($entityManager))->forget($storage);

        $this->assertSame([], glob($this->dir.'/*'));
    }

    public function testRemoveKeepsForeignSectionsAndKeys(): void
    {
        $this->marker(5, "[.ShellClassInfo]\nInfoTip=Hello\n");
        $service = $this->service();
        $storage = $this->storage();
        $service->apply($storage);

        $service->remove($storage);

        $this->assertSame("[AnimeDB]\nid=5\n[.ShellClassInfo]\nInfoTip=Hello\n", file_get_contents($this->dir.'/desktop.ini'));
        $this->assertFileDoesNotExist($this->dir.'/animedb-folder.ico');
    }

    public function testRemoveWithOldPathIgnoresRootOfAnotherStorage(): void
    {
        $this->marker(7);
        file_put_contents($this->dir.'/autorun.inf', $this->autorun('animedb-folder.ico', 'Other'));
        touch($this->dir.'/animedb-folder.ico');

        $this->service(true)->remove($this->storage(5), $this->dir);

        $this->assertFileExists($this->dir.'/autorun.inf');
        $this->assertFileExists($this->dir.'/animedb-folder.ico');
    }

    public function testRemoveLeavesForeignAutorunAndIcon(): void
    {
        $this->marker();
        file_put_contents($this->dir.'/autorun.inf', "[autorun]\nicon=my.ico\n");
        file_put_contents($this->dir.'/desktop.ini', "[AnimeDB]\nid=5\n[.ShellClassInfo]\nIconResource=my.ico,0\n");
        $before = (string) file_get_contents($this->dir.'/desktop.ini');

        $this->service(true)->remove($this->storage());

        $this->assertSame("[autorun]\nicon=my.ico\n", file_get_contents($this->dir.'/autorun.inf'));
        $this->assertSame($before, file_get_contents($this->dir.'/desktop.ini'));
    }

    public function testWriteFailureIsLoggedNotThrown(): void
    {
        $this->marker();
        mkdir($this->dir.'/autorun.inf');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $this->service(true, $logger)->apply($this->storage());

        $this->assertDirectoryExists($this->dir.'/autorun.inf');
    }

    public function testIconCopyFailureIsLoggedNotThrown(): void
    {
        $this->marker();
        mkdir($this->dir.'/animedb-folder.ico');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $this->service(false, $logger)->apply($this->storage());

        $this->assertStringNotContainsString('IconResource', (string) file_get_contents($this->dir.'/desktop.ini'));
    }

    public function testUnparsableDesktopIniIsNotWritten(): void
    {
        $bytes = "\xFF\xFE".mb_convert_encoding("[AnimeDB]\r\nid=5\r\n", 'UTF-16LE', 'UTF-8');
        file_put_contents($this->dir.'/desktop.ini', $bytes);

        $this->service()->apply($this->storage());

        $this->assertSame($bytes, file_get_contents($this->dir.'/desktop.ini'));
        $this->assertFileDoesNotExist($this->dir.'/animedb-folder.ico');
    }
}
