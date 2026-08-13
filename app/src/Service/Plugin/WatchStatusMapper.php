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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Sync\SyncStatus;
use App\Entity\Enum\WatchStatus;

/**
 * Translates the host application's own {@see WatchStatus} into the contract-level
 * {@see SyncStatus} vocabulary sync plugins speak (issue #214). Kept as an explicit mapping
 * rather than relying on the two enums sharing the same case names/values: WatchStatus is
 * this application's own type and free to diverge from the plugin contract in the future,
 * so a push must not silently break if it does.
 */
final class WatchStatusMapper
{
    public static function toSyncStatus(WatchStatus $watchStatus): SyncStatus
    {
        return match ($watchStatus) {
            WatchStatus::Plan => SyncStatus::Plan,
            WatchStatus::Watching => SyncStatus::Watching,
            WatchStatus::Completed => SyncStatus::Completed,
            WatchStatus::Dropped => SyncStatus::Dropped,
            WatchStatus::OnHold => SyncStatus::OnHold,
        };
    }

    /**
     * Reverse of toSyncStatus(), used by the pull direction of sync (issue #257) to translate
     * a plugin's SyncItem::$status back into this application's own WatchStatus.
     */
    public static function toWatchStatus(SyncStatus $status): WatchStatus
    {
        return match ($status) {
            SyncStatus::Plan => WatchStatus::Plan,
            SyncStatus::Watching => WatchStatus::Watching,
            SyncStatus::Completed => WatchStatus::Completed,
            SyncStatus::Dropped => WatchStatus::Dropped,
            SyncStatus::OnHold => WatchStatus::OnHold,
        };
    }
}
