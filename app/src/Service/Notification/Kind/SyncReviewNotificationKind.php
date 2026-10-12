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
 * A sync run (periodic pull or connect-seed) left more unresolved "requires attention" items than it
 * started with (issue #1028). One global record: while it is unread, later runs refresh the count
 * instead of adding a new record. Recorded by the handlers after the run, never per review item.
 */
final class SyncReviewNotificationKind implements NotificationKindInterface
{
    public const string KIND = 'sync_review';
    public const string DEDUP_KEY = 'global';

    public static function draft(int $unresolvedCount): NotificationDraft
    {
        return new NotificationDraft(
            self::KIND,
            ['count' => $unresolvedCount],
            dedupKey: self::DEDUP_KEY,
        );
    }

    public function definition(): NotificationKindDefinition
    {
        return new NotificationKindDefinition(
            self::KIND,
            NotificationSeverity::Warning,
            NotificationMergePolicy::CoalesceUnread,
            [
                'count' => new NotificationParam(NotificationParamType::Int),
            ],
            static fn (): NotificationDraft => self::draft(3),
        );
    }
}
