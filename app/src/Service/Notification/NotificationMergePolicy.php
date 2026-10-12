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

namespace App\Service\Notification;

/**
 * How repeated events of one kind with the same key are recorded: `always` — every event is a new
 * record; `coalesce_unread` — an unread record with the same key absorbs the event; `once` — the
 * key fires once for all time.
 */
enum NotificationMergePolicy: string
{
    case Always = 'always';
    case CoalesceUnread = 'coalesce_unread';
    case Once = 'once';

    public function requiresKey(): bool
    {
        return $this !== self::Always;
    }
}
