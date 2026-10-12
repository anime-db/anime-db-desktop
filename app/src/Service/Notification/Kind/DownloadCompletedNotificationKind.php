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

/** A download finished and its folder is linked to the catalog entry (issue #1026). */
final class DownloadCompletedNotificationKind implements NotificationKindInterface
{
    public const string KIND = 'download_completed';

    public static function draft(string $animeTitle, int $animeId): NotificationDraft
    {
        return new NotificationDraft(self::KIND, ['name' => $animeTitle], 'anime', (string) $animeId);
    }

    public function definition(): NotificationKindDefinition
    {
        return new NotificationKindDefinition(
            self::KIND,
            NotificationSeverity::Success,
            NotificationMergePolicy::Always,
            ['name' => new NotificationParam(NotificationParamType::String)],
            static fn (): NotificationDraft => self::draft('Cowboy Bebop', 7),
        );
    }
}
