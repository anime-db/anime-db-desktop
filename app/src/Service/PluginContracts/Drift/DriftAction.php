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

namespace App\Service\PluginContracts\Drift;

enum DriftAction: string
{
    /** Not lagging, no open issue: nothing to do. */
    case NONE = 'none';

    /** Not lagging, an open issue exists: it is stale, close it. */
    case CLOSE = 'close';

    /** Lagging, no open issue: file a new one. */
    case CREATE = 'create';

    /** Lagging, open issue exists, same contracts version and reason: refresh the body only. */
    case REWRITE_BODY = 'rewrite_body';

    /** Lagging, open issue exists, version or reason changed (or the marker is unreadable): refresh the body and comment. */
    case REWRITE_BODY_AND_COMMENT = 'rewrite_body_and_comment';

    /** The state of the plugin cannot be determined reliably: touch nothing. */
    case CANNOT_CHECK = 'cannot_check';
}
