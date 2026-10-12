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
 * A download moved from Pending to Failed (issue #1026). `reason` is the failure reason stored on
 * the download; null is a failure without one and renders a generic text. A new failure reason of
 * `Download::markFailed()` is added to {@see self::REASONS} together with its ru/en texts.
 */
final class DownloadFailedKind implements NotificationKindInterface
{
    public const string KIND = 'download_failed';

    /** The closed set of non-null `reason` codes. */
    public const array REASONS = ['disk_space', 'unexpected_layout', 'storage_conflict', 'name_conflict', 'move_failed'];

    public static function downloadFailed(string $animeTitle, int $animeId, ?string $reason): NotificationDraft
    {
        if ($reason !== null && !\in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown download failure reason "%s".', $reason));
        }

        return new NotificationDraft(self::KIND, ['name' => $animeTitle, 'reason' => $reason], 'anime', (string) $animeId);
    }

    public function definition(): NotificationKindDefinition
    {
        return new NotificationKindDefinition(
            self::KIND,
            NotificationSeverity::Error,
            NotificationMergePolicy::Always,
            [
                'name' => new NotificationParam(NotificationParamType::String),
                'reason' => new NotificationParam(NotificationParamType::String, nullable: true, codes: self::REASONS),
            ],
            static fn (): NotificationDraft => self::downloadFailed('Cowboy Bebop', 7, 'disk_space'),
        );
    }
}
