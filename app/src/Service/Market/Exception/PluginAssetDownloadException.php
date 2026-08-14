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

namespace App\Service\Market\Exception;

use App\Entity\ValueObject\PluginId;

/**
 * Thrown by {@see \App\Service\Market\MarketAssetDownloader::downloadPluginZip()} when every
 * mirror in `asset_mirrors` either failed to serve the asset or served bytes whose sha256 does
 * not match the registry's pinned checksum for this plugin/version. A checksum mismatch on one
 * mirror does not fail the install outright — the next mirror is tried, since the registry's
 * signature already guarantees the *expected* sha256 is trustworthy, so a mismatch just means
 * that particular mirror served something else (truncated transfer, stale/corrupt copy).
 */
final class PluginAssetDownloadException extends \RuntimeException
{
    /**
     * @param array<string, string> $failuresByUrl reason keyed by the derived asset URL that failed
     */
    public function __construct(
        public readonly PluginId $pluginId,
        public readonly string $version,
        public readonly array $failuresByUrl,
    ) {
        parent::__construct(\sprintf(
            'Failed to download a verified "%s" %s archive from all %d mirror(s): %s',
            $pluginId,
            $version,
            \count($failuresByUrl),
            implode('; ', array_map(
                static fn (string $url, string $reason): string => \sprintf('%s (%s)', $url, $reason),
                array_keys($failuresByUrl),
                $failuresByUrl,
            )),
        ));
    }
}
