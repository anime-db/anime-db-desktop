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

namespace App\Service\Storage;

use App\Entity\Storage;

/**
 * The `is_readable()` check AnimeViewFactory::serializeStorage() already runs per-anime (issue
 * #654), extracted here (issue #834) so both {@see \App\Controller\StorageController} (the
 * storage list) and the top nav "Add" menu's scan section can share the same notion of "connected"
 * without each reimplementing the file check.
 */
final class StorageAvailabilityService
{
    /**
     * @param Storage[] $storages
     *
     * @return list<int>
     */
    public function unavailableStorageIds(array $storages): array
    {
        $ids = [];
        foreach ($storages as $storage) {
            if (!is_readable($storage->getPath())) {
                $ids[] = $storage->id ?? throw new \LogicException('Storage must be persisted before its path can be checked.');
            }
        }

        return $ids;
    }
}
