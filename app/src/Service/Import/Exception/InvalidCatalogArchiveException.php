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
 * Thrown by {@see \App\Service\Import\CatalogStageService::stage()} for every way an archive can
 * be rejected before anything is written to the staging directory (issue #669): the archive
 * cannot be opened, has no valid `manifest.json`, declares a `formatVersion` this build does not
 * support, has no `data.db`, or contains an unsafe entry path.
 *
 * `$reasonKey` identifies which of those it was, so {@see \App\Command\CatalogStageCommand} can
 * look up a translated `catalog_stage.error_<reasonKey>` message ({@see self::REASON_*} constants
 * list every value it uses) without parsing {@see self::getMessage()}. `$params` are the
 * placeholders that message needs, already keyed the way `trans()` expects.
 */
final class InvalidCatalogArchiveException extends \RuntimeException
{
    public const string REASON_UNREADABLE = 'unreadable';
    public const string REASON_MISSING_MANIFEST = 'missing_manifest';
    public const string REASON_UNSUPPORTED_FORMAT_VERSION = 'unsupported_format_version';
    public const string REASON_MISSING_DATABASE = 'missing_database';
    public const string REASON_UNSAFE_ENTRY = 'unsafe_entry';

    /**
     * @param array<string, string|int> $params
     */
    public function __construct(
        public readonly string $reasonKey,
        public readonly array $params,
        string $message,
    ) {
        parent::__construct($message);
    }
}
