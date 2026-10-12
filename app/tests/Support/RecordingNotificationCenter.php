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

namespace App\Tests\Support;

use App\Service\Notification\NotificationCenter;
use App\Service\Notification\NotificationDraft;

/** A {@see NotificationCenter} that only remembers the drafts it was asked to record. */
final class RecordingNotificationCenter extends NotificationCenter
{
    /** @var list<NotificationDraft> */
    public array $drafts = [];

    /** @var (\Closure(NotificationDraft): void)|null called at the moment of record(), e.g. to look at the database */
    public ?\Closure $onRecord = null;

    public function __construct(private readonly bool $failing = false)
    {
    }

    public function record(NotificationDraft $draft): void
    {
        if ($this->failing) {
            throw new \RuntimeException('record() failed');
        }
        if ($this->onRecord !== null) {
            ($this->onRecord)($draft);
        }
        $this->drafts[] = $draft;
    }
}
