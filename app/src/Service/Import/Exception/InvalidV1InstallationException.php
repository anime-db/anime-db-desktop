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

namespace App\Service\Import\Exception;

/**
 * Thrown by {@see \App\Service\Import\V1\V1ImportService::import()} when the given directory
 * cannot be imported, before anything is written: it is not an AnimeDB v1 installation, or the
 * catalog is not empty, or a record of the catalog breaks an invariant of v2 (nothing is written then).
 *
 * `$reasonKey` selects the translated `import_v1.error_<reasonKey>` message, `$params` are its
 * placeholders — the same shape as {@see InvalidCatalogArchiveException}.
 */
final class InvalidV1InstallationException extends \RuntimeException
{
    public const string REASON_NOT_V1_INSTALLATION = 'not_v1_installation';
    public const string REASON_CATALOG_NOT_EMPTY = 'catalog_not_empty';
    public const string REASON_INVALID_RECORD = 'invalid_record';

    /**
     * @param array<string, string|int> $params
     */
    public function __construct(
        public readonly string $reasonKey,
        public readonly array $params,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
