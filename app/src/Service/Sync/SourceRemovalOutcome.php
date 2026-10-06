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

namespace App\Service\Sync;

/** What {@see SourceRemovalService::remove()} did with one pending deletion (issue #918). */
enum SourceRemovalOutcome
{
    /** Removed on the source, the tombstone is gone. */
    case Removed;
    /** The tombstone is not there or does not wait for the deletion any more. */
    case NotPending;
    /** A live entry holds the pair again; the flag was cleared, nothing was removed. */
    case Cancelled;
    /** The plugin does not support removal; the flag stays. */
    case Unsupported;
}
