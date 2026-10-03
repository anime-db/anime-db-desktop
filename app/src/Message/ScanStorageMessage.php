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

namespace App\Message;

/**
 * Dispatched on the `async` transport to scan a single Storage in the background
 * (Таск 3 часть 6) — carries only the id, the handler loads the current entity state itself.
 */
final readonly class ScanStorageMessage
{
    public function __construct(
        public int $storageId,
    ) {
    }

    /**
     * The {@see \App\Service\JobLock\JobLockService} key a scan of $storageId runs under —
     * shared by {@see \App\MessageHandler\ScanStorageMessageHandler}, which acquires it, and
     * {@see \App\Controller\StorageController::scanProgress()} (issue #834 review), which only
     * reads whether it is currently held, so both sides can never drift onto different keys for
     * the same storage.
     */
    public static function jobKey(int $storageId): string
    {
        return \sprintf('scan:storage:%d', $storageId);
    }
}
