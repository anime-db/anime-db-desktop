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

namespace App\Tests\Unit\Service\Storage;

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Service\Storage\StorageMarkerResult;
use App\Service\Storage\StorageMarkerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class StorageMarkerServiceTest extends TestCase
{
    private string $storageDir;
    private string $otherDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir().'/storage-marker-test-'.uniqid();
        $this->otherDir = sys_get_temp_dir().'/storage-marker-test-other-'.uniqid();
        mkdir($this->storageDir, recursive: true);
        mkdir($this->otherDir, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->storageDir, $this->otherDir] as $dir) {
            $marker = $dir.'/desktop.ini';
            if (is_file($marker)) {
                unlink($marker);
            }
            rmdir($dir);
        }
    }

    private function stubEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);

        return $entityManager;
    }

    private function storageWithId(int $id, string $path): Storage
    {
        $storage = new Storage('Main folder', $path, StorageType::Folder);
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);

        return $storage;
    }

    /** @return array<string, array<string, mixed>> */
    private function readMarkerSections(string $storageDir): array
    {
        $sections = parse_ini_file($storageDir.'/desktop.ini', true, \INI_SCANNER_RAW);
        $this->assertIsArray($sections);

        return $sections;
    }

    public function testReconcileCreatesMarkerWhenMissing(): void
    {
        $storage = $this->storageWithId(5, $this->storageDir);
        $service = new StorageMarkerService($this->stubEntityManager());

        $result = $service->reconcile($storage);

        $this->assertSame(StorageMarkerResult::Created, $result);
        $sections = $this->readMarkerSections($this->storageDir);
        $this->assertSame('5', $sections['AnimeDB']['id']);
    }

    public function testReconcileDoesNothingWhenMarkerOwnedBySameStorage(): void
    {
        file_put_contents($this->storageDir.'/desktop.ini', "[AnimeDB]\nid=5\n");
        $storage = $this->storageWithId(5, $this->storageDir);
        $service = new StorageMarkerService($this->stubEntityManager());

        $result = $service->reconcile($storage);

        $this->assertSame(StorageMarkerResult::Owned, $result);
        $this->assertSame("[AnimeDB]\nid=5\n", file_get_contents($this->storageDir.'/desktop.ini'));
    }

    public function testReconcileRewritesMarkerWhenOwnerStorageWasDeleted(): void
    {
        file_put_contents($this->storageDir.'/desktop.ini', "[AnimeDB]\nid=999\n");
        $storage = $this->storageWithId(5, $this->storageDir);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->with(Storage::class, 999)->willReturn(null);
        $service = new StorageMarkerService($entityManager);

        $result = $service->reconcile($storage);

        $this->assertSame(StorageMarkerResult::Reclaimed, $result);
        $sections = $this->readMarkerSections($this->storageDir);
        $this->assertSame('5', $sections['AnimeDB']['id']);
    }

    public function testReconcileConflictsWhenMarkerOwnedByAnotherActiveStorage(): void
    {
        file_put_contents($this->storageDir.'/desktop.ini', "[AnimeDB]\nid=888\n");
        $storage = $this->storageWithId(5, $this->storageDir);
        $otherStorage = $this->storageWithId(888, $this->otherDir);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->with(Storage::class, 888)->willReturn($otherStorage);
        $service = new StorageMarkerService($entityManager);

        $result = $service->reconcile($storage);

        $this->assertSame(StorageMarkerResult::Conflict, $result);
        $sections = $this->readMarkerSections($this->storageDir);
        $this->assertSame('888', $sections['AnimeDB']['id']);
    }

    public function testReconcilePreservesUnrelatedSectionsOnRewrite(): void
    {
        file_put_contents($this->storageDir.'/desktop.ini', "[.ShellClassInfo]\nIconResource=icon.ico,0\n");
        $storage = $this->storageWithId(5, $this->storageDir);
        $service = new StorageMarkerService($this->stubEntityManager());

        $service->reconcile($storage);

        $sections = $this->readMarkerSections($this->storageDir);
        $this->assertSame('icon.ico,0', $sections['.ShellClassInfo']['IconResource']);
        $this->assertSame('5', $sections['AnimeDB']['id']);
    }

    public function testRelocateIfMarkerMovedUpdatesPathWhenMarkerMatchesElsewhere(): void
    {
        file_put_contents($this->otherDir.'/desktop.ini', "[AnimeDB]\nid=5\n");
        $storage = $this->storageWithId(5, $this->storageDir);
        $service = new StorageMarkerService($this->stubEntityManager());

        $relocated = $service->relocateIfMarkerMoved($storage, $this->otherDir);

        $this->assertTrue($relocated);
        $this->assertSame($this->otherDir, $storage->getPath());
    }

    public function testRelocateIfMarkerMovedDoesNothingWhenMarkerIdDiffers(): void
    {
        file_put_contents($this->otherDir.'/desktop.ini', "[AnimeDB]\nid=999\n");
        $storage = $this->storageWithId(5, $this->storageDir);
        $service = new StorageMarkerService($this->stubEntityManager());

        $relocated = $service->relocateIfMarkerMoved($storage, $this->otherDir);

        $this->assertFalse($relocated);
        $this->assertSame($this->storageDir, $storage->getPath());
    }

    public function testRelocateIfMarkerMovedDoesNothingWhenPathAlreadyMatches(): void
    {
        file_put_contents($this->storageDir.'/desktop.ini', "[AnimeDB]\nid=5\n");
        $storage = $this->storageWithId(5, $this->storageDir);
        $service = new StorageMarkerService($this->stubEntityManager());

        $relocated = $service->relocateIfMarkerMoved($storage, $this->storageDir);

        $this->assertFalse($relocated);
    }
}
