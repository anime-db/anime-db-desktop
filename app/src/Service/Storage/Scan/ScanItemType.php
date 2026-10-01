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

namespace App\Service\Storage\Scan;

enum ScanItemType
{
    /** A known file's mtime moved past its Anime::$dateUpdate; the caller should refresh it. */
    case Updated;

    /** A known Anime has no matching file left on disk (v1's DELETE_ITEM_FILES equivalent). */
    case FilesMissing;

    /** Exactly one candidate was found; ScanStorageService already performed the binding. */
    case AutoLinked;

    /** More than one candidate was found; the caller must ask the user to pick one. */
    case NeedsConfirmation;

    /** No candidate was found; the caller must offer to create a catalog entry manually. */
    case NeedsManualEntry;

    /**
     * The single plugin candidate resolved (by pluginId/externalId) to a catalog record already
     * linked to a different storage path (issue #832) — the caller must show this as "already in
     * the catalog, linked to <path>" rather than silently re-linking or failing the whole scan.
     */
    case Conflict;

    /**
     * Processing this entry (resolving candidates, creating/filling in its Anime) failed (issue
     * #832) — isolated to this one item so the rest of the scan still completes; the caller
     * should show this as a per-item error rather than aborting.
     */
    case Error;
}
