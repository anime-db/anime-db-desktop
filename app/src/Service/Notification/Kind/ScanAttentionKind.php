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

namespace App\Service\Notification\Kind;

use App\Service\Notification\NotificationDraft;
use App\Service\Notification\NotificationKindDefinition;
use App\Service\Notification\NotificationMergePolicy;
use App\Service\Notification\NotificationParam;
use App\Service\Notification\NotificationParamType;
use App\Service\Notification\NotificationSeverity;

/**
 * A finished background scan left items that need the user's action (issue #1026). Merged by
 * storage while unread: a repeated scan refreshes the counters of the unread record.
 */
final class ScanAttentionKind implements NotificationKindInterface
{
    public const string KIND = 'scan_attention';

    public static function scanAttention(
        string $storageName,
        int $needsConfirmation,
        int $needsManualEntry,
        int $filesMissing,
        int $conflict,
        int $error,
        int $storageId,
        ?int $runId = null,
    ): NotificationDraft {
        return new NotificationDraft(
            self::KIND,
            [
                'name' => $storageName,
                'needs_confirmation' => $needsConfirmation,
                'needs_manual_entry' => $needsManualEntry,
                'files_missing' => $filesMissing,
                'conflict' => $conflict,
                'error' => $error,
            ],
            ...ScanRunSubject::of($storageId, $runId),
            dedupKey: 'storage:'.$storageId,
        );
    }

    public function definition(): NotificationKindDefinition
    {
        return new NotificationKindDefinition(
            self::KIND,
            NotificationSeverity::Warning,
            NotificationMergePolicy::CoalesceUnread,
            [
                'name' => new NotificationParam(NotificationParamType::String),
                'needs_confirmation' => new NotificationParam(NotificationParamType::Int),
                'needs_manual_entry' => new NotificationParam(NotificationParamType::Int),
                'files_missing' => new NotificationParam(NotificationParamType::Int),
                'conflict' => new NotificationParam(NotificationParamType::Int),
                'error' => new NotificationParam(NotificationParamType::Int),
            ],
            static fn (): NotificationDraft => self::scanAttention('Anime', 2, 1, 0, 3, 1, 3, 12),
        );
    }
}
