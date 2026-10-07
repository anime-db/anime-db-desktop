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

namespace App\Tests\Unit\Entity;

use App\Entity\Enum\StorageType;
use App\Entity\Exception\InvalidNameException;
use App\Entity\Exception\InvalidPathException;
use App\Entity\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    public function testConstructSetsNamePathAndType(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);

        $this->assertSame('Main folder', $storage->getName());
        $this->assertSame('D:\\Anime', $storage->getPath());
        $this->assertSame(StorageType::Folder, $storage->getType());
    }

    public function testConstructRejectsEmptyName(): void
    {
        $this->expectException(InvalidNameException::class);

        new Storage('', 'D:\\Anime', StorageType::Folder);
    }

    public function testConstructRejectsEmptyPath(): void
    {
        $this->expectException(InvalidPathException::class);

        new Storage('Main folder', '', StorageType::Folder);
    }

    public function testConstructRejectsRelativePath(): void
    {
        $this->expectException(InvalidPathException::class);

        new Storage('Main folder', 'Anime\\Folder', StorageType::Folder);
    }

    public function testConstructAcceptsUncPath(): void
    {
        $storage = new Storage('Main folder', '\\\\nas\\anime', StorageType::Folder);

        $this->assertSame('\\\\nas\\anime', $storage->getPath());
    }

    public function testRenameTrimsAndUpdatesName(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $storage->rename(' New name ');

        $this->assertSame('New name', $storage->getName());
    }

    public function testRenameRejectsEmptyName(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);

        $this->expectException(InvalidNameException::class);

        $storage->rename('   ');
    }

    public function testRelocateUpdatesPath(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $storage->relocate('E:\\Anime');

        $this->assertSame('E:\\Anime', $storage->getPath());
    }

    public function testRelocateRejectsRelativePath(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);

        $this->expectException(InvalidPathException::class);

        $storage->relocate('Anime');
    }

    public function testSetAndGetType(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::External);
        $storage->setType(StorageType::Folder);

        $this->assertSame(StorageType::Folder, $storage->getType());
    }

    public function testDateUpdateDefaultsToNull(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);

        $this->assertNull($storage->getDateUpdate());
    }

    public function testFileModifiedDefaultsToNull(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);

        $this->assertNull($storage->getFileModified());
    }

    public function testMarkScannedSetsFileModified(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $fileModified = new \DateTimeImmutable('2026-07-01 12:00:00');
        $storage->markScanned($fileModified);

        $this->assertSame($fileModified, $storage->getFileModified());
    }

    public function testMarkScannedSetsDateUpdateToNow(): void
    {
        $storage = new Storage('Main folder', 'D:\\Anime', StorageType::Folder);
        $before = new \DateTimeImmutable();
        $storage->markScanned(new \DateTimeImmutable('2026-07-01 12:00:00'));

        $this->assertGreaterThanOrEqual($before, $storage->getDateUpdate());
    }

    /** @return iterable<string, array{StorageType}> */
    public static function pathOptionalTypes(): iterable
    {
        yield 'external-r' => [StorageType::ExternalR];
        yield 'video' => [StorageType::Video];
    }

    /** @return iterable<string, array{StorageType, ?string}> */
    public static function missingPaths(): iterable
    {
        foreach ([StorageType::Folder, StorageType::External] as $type) {
            yield $type->value.' null' => [$type, null];
            yield $type->value.' empty' => [$type, ''];
            yield $type->value.' blank' => [$type, '  '];
            yield $type->value.' relative' => [$type, 'Anime\\Folder'];
        }
    }

    #[DataProvider('pathOptionalTypes')]
    public function testPathOptionalTypeIsCreatedWithoutPath(StorageType $type): void
    {
        $this->assertNull((new Storage('Disc', null, $type))->getPath());
        $this->assertNull((new Storage('Disc', '', $type))->getPath());
    }

    #[DataProvider('pathOptionalTypes')]
    public function testPathOptionalTypeKeepsNonEmptyPath(StorageType $type): void
    {
        $this->assertSame('E:\\', (new Storage('Disc', 'E:\\', $type))->getPath());
    }

    #[DataProvider('pathOptionalTypes')]
    public function testPathOptionalTypeStillRejectsRelativePath(StorageType $type): void
    {
        $this->expectException(InvalidPathException::class);

        new Storage('Disc', 'relative', $type);
    }

    #[DataProvider('missingPaths')]
    public function testPathRequiredTypeRejectsMissingPath(StorageType $type, ?string $path): void
    {
        $this->expectException(InvalidPathException::class);

        new Storage('Main', $path, $type);
    }

    public function testRelocateClearsPathOfPathOptionalType(): void
    {
        $storage = new Storage('Disc', 'E:\\', StorageType::ExternalR);

        $storage->relocate(null);

        $this->assertNull($storage->getPath());
    }

    public function testRelocateValidatesAgainstTheCurrentType(): void
    {
        $storage = new Storage('Disc', null, StorageType::ExternalR);
        $storage->setType(StorageType::Folder);

        $this->expectException(InvalidPathException::class);

        $storage->relocate(null);
    }

    public function testRequirePathThrowsWithoutPath(): void
    {
        $this->expectException(\LogicException::class);

        (new Storage('Disc', null, StorageType::Video))->requirePath();
    }
}
