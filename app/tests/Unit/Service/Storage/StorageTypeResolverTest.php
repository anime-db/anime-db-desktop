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
use App\Service\Storage\StorageTypeResolver;
use App\Service\Storage\VolumeInfo;
use App\Service\Storage\VolumeProbeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageTypeResolverTest extends TestCase
{
    /** @return array<string, array{?string, ?string, ?StorageType}> */
    public static function volumes(): array
    {
        return [
            'cd-rom' => ['CDRom', null, StorageType::ExternalR],
            'removable' => ['Removable', null, StorageType::External],
            'network' => ['Network', null, StorageType::Folder],
            'fixed usb' => ['Fixed', 'USB', StorageType::External],
            'fixed sd' => ['Fixed', 'SD', StorageType::External],
            'fixed mmc' => ['Fixed', 'MMC', StorageType::External],
            'fixed 1394' => ['Fixed', '1394', StorageType::External],
            'fixed sata' => ['Fixed', 'SATA', StorageType::Folder],
            'fixed nvme' => ['Fixed', 'NVMe', StorageType::Folder],
            'fixed raid' => ['Fixed', 'RAID', StorageType::Folder],
            'fixed sas' => ['Fixed', 'SAS', StorageType::Folder],
            'fixed storage spaces' => ['Fixed', 'Storage Spaces', StorageType::Folder],
            'fixed file-backed virtual' => ['Fixed', 'File Backed Virtual', StorageType::Folder],
            'fixed fibre channel' => ['Fixed', 'Fibre Channel', StorageType::Folder],
            'fixed bus without spaces' => ['Fixed', 'FileBackedVirtual', StorageType::Folder],
            'fixed iscsi' => ['Fixed', 'iSCSI', StorageType::Folder],
            'fixed virtual' => ['Fixed', 'Virtual', StorageType::Folder],
            'fixed empty bus' => ['Fixed', '', null],
            'fixed null bus' => ['Fixed', null, null],
            'fixed unknown bus' => ['Fixed', 'Unknown', null],
            'ram' => ['Ram', 'SATA', null],
            'unknown' => ['Unknown', 'USB', null],
            'no root directory' => ['NoRootDirectory', 'USB', null],
            'no drive type' => [null, 'USB', null],
        ];
    }

    #[DataProvider('volumes')]
    public function testResolvesByTheVolumeOfADrivePath(?string $driveType, ?string $busType, ?StorageType $expected): void
    {
        $probe = $this->createStub(VolumeProbeInterface::class);
        $resolver = new StorageTypeResolver($probe);

        $this->assertSame($expected, $resolver->resolve('D:\\Anime', new VolumeInfo(null, $driveType, $busType)));
    }

    public function testFailedOrTimedOutProbeIsUndetermined(): void
    {
        $this->assertNull((new StorageTypeResolver($this->createStub(VolumeProbeInterface::class)))->resolve('D:\\', null));
    }

    public function testNoRuleSuggestsTheVideoType(): void
    {
        $resolver = new StorageTypeResolver($this->createStub(VolumeProbeInterface::class));

        foreach (['CDRom', 'Removable', 'Network', 'Fixed', 'Ram'] as $driveType) {
            foreach (['USB', 'SATA', '', null] as $bus) {
                $this->assertNotSame(StorageType::Video, $resolver->resolve('D:/x', new VolumeInfo(null, $driveType, $bus)));
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function pathsThatNeverReachTheProbe(): array
    {
        return [
            'unc' => ['\\\\srv\\share'],
            'unc with slashes' => ['//srv/share'],
            'extended prefix' => ['\\\\?\\D:\\Anime'],
            'no separator after the letter' => ['D:foo'],
            'relative' => ['Anime\\D:\\'],
            'empty' => [''],
        ];
    }

    #[DataProvider('pathsThatNeverReachTheProbe')]
    public function testProbeIsNotRunForPathsWithoutADriveLetter(string $path): void
    {
        $probe = $this->createMock(VolumeProbeInterface::class);
        $probe->expects($this->never())->method('probe');
        $resolver = new StorageTypeResolver($probe);

        $this->assertNull($resolver->probe($path));
    }

    public function testUncPathsAreFoldersFromTheStringAlone(): void
    {
        $resolver = new StorageTypeResolver($this->createStub(VolumeProbeInterface::class));

        $this->assertSame(StorageType::Folder, $resolver->resolve('\\\\srv\\share', null));
        $this->assertSame(StorageType::Folder, $resolver->resolve('\\\\?\\D:\\Anime', new VolumeInfo(null, 'Removable', null)));
        $this->assertNull($resolver->resolve('D:foo', new VolumeInfo(null, 'Removable', null)));
    }

    public function testOnlyTheLetterIsHandedToTheProbe(): void
    {
        $probe = $this->createMock(VolumeProbeInterface::class);
        $probe->expects($this->exactly(2))->method('probe')->with('D', true)->willReturn(null);
        $resolver = new StorageTypeResolver($probe);

        $resolver->probe('d:\\Some folder\\..\\x');
        $resolver->probe('D:/Anime');
    }
}
