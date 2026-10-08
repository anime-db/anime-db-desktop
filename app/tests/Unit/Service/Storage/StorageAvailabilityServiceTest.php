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
use App\Service\Storage\StorageAvailabilityService;
use PHPUnit\Framework\TestCase;

final class StorageAvailabilityServiceTest extends TestCase
{
    public function testStorageWithoutPathIsNotUnavailable(): void
    {
        $withoutPath = new Storage('Disc', null, StorageType::ExternalR);
        $missing = new Storage('Gone', '/nonexistent-anime-db-path', StorageType::Folder);
        $this->setId($withoutPath, 1);
        $this->setId($missing, 2);

        $this->assertSame([2], (new StorageAvailabilityService())->unavailableStorageIds([$withoutPath, $missing]));
    }

    private function setId(Storage $storage, int $id): void
    {
        (new \ReflectionProperty(Storage::class, 'id'))->setValue($storage, $id);
    }
}
