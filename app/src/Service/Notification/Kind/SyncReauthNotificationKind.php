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
 * A sync plugin's OAuth session died and the plugin needs to be authorized again (issue #1027).
 * Recorded once per false→true transition of the plugin's `needs_reauth` flag; the subject is the
 * plugin (its settings page), `plugin_name` is the display name at the time of the event because
 * the plugin may be removed later.
 */
final class SyncReauthNotificationKind implements NotificationKindInterface
{
    public const KIND = 'sync_reauth';
    public const SUBJECT_PLUGIN = 'plugin';

    public static function draft(string $pluginId, string $pluginName): NotificationDraft
    {
        return new NotificationDraft(
            self::KIND,
            ['plugin_id' => $pluginId, 'plugin_name' => $pluginName],
            self::SUBJECT_PLUGIN,
            $pluginId,
            $pluginId,
        );
    }

    public function definition(): NotificationKindDefinition
    {
        return new NotificationKindDefinition(
            self::KIND,
            NotificationSeverity::Warning,
            NotificationMergePolicy::CoalesceUnread,
            [
                'plugin_id' => new NotificationParam(NotificationParamType::String),
                'plugin_name' => new NotificationParam(NotificationParamType::String),
            ],
            static fn (): NotificationDraft => self::draft('sample-sync', 'Sample Sync'),
        );
    }
}
