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

/** A background storage scan failed (issue #1026). The subject is the scan run, when the journal has one. */
final class ScanFailedKind implements NotificationKindInterface
{
    public const string KIND = 'scan_failed';

    public const string REASON_MARKER_CONFLICT = 'marker_conflict';
    public const string REASON_EXCEPTION = 'exception';

    /** The closed set of `reason` codes. */
    public const array REASONS = [self::REASON_MARKER_CONFLICT, self::REASON_EXCEPTION];

    public static function scanFailed(string $storageName, string $reason, ?int $storageId = null, ?int $runId = null): NotificationDraft
    {
        if (!\in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown scan failure reason "%s".', $reason));
        }

        return new NotificationDraft(
            self::KIND,
            ['name' => $storageName, 'reason' => $reason],
            ...ScanRunSubject::of($storageId, $runId),
        );
    }

    public function definition(): NotificationKindDefinition
    {
        return new NotificationKindDefinition(
            self::KIND,
            NotificationSeverity::Error,
            NotificationMergePolicy::Always,
            [
                'name' => new NotificationParam(NotificationParamType::String),
                'reason' => new NotificationParam(NotificationParamType::String, codes: self::REASONS),
            ],
            static fn (): NotificationDraft => self::scanFailed('Anime', self::REASON_MARKER_CONFLICT, 3, 12),
        );
    }
}
