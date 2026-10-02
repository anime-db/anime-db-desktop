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

namespace App\Service\Storage\Exception;

use App\Entity\Anime;

/**
 * Thrown by ScanStorageService::linkToChosenCandidate() (issue #147) when the requested
 * storage_path is already occupied by another Anime, or when the chosen candidate — an orphan,
 * or (issue #832) a catalog record resolved by (pluginId, externalId) — is already linked
 * elsewhere — both signal a lost race between two confirm requests (two tabs, a double click,
 * or a stale scan.done payload) rather than a normal error.
 *
 * $anime/$alreadyLinkedStoragePath (issue #832) are only populated for the "candidate already
 * linked elsewhere" case — the one a caller can turn into a structured "already in the catalog,
 * linked to <path>" response instead of a generic error; the "requested path already occupied"
 * case still leaves both null and is reported as a plain message.
 */
final class StoragePathConflictException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?Anime $anime = null,
        public readonly ?string $alreadyLinkedStoragePath = null,
    ) {
        parent::__construct($message);
    }
}
