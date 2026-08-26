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

namespace App\Service\I18nCoverage;

/**
 * The five outcomes {@see I18nCoverageIssueDecider} can reach, one per row of the state table in
 * issue #515.
 */
enum I18nCoverageAction: string
{
    /** Delta empty, no open issue: nothing to do. */
    case NONE = 'none';

    /** Delta empty, an open issue exists: it is now stale, close it. */
    case CLOSE = 'close';

    /** Delta non-empty, no open issue: file a new one. */
    case CREATE = 'create';

    /** Delta non-empty, open issue exists, delta unchanged since last run: refresh the body only. */
    case REWRITE_BODY = 'rewrite_body';

    /** Delta non-empty, open issue exists, delta changed since last run: refresh the body and comment. */
    case REWRITE_BODY_AND_COMMENT = 'rewrite_body_and_comment';
}
