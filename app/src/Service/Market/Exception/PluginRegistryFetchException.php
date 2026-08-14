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

/**
 * Thrown by {@see \App\Service\Market\PluginRegistryFetcher::fetch()} when every configured
 * registry mirror failed to return both `plugins-registry.json` and its detached signature —
 * a purely transport-level failure (network error, timeout, non-2xx response), separate from a
 * mirror that answered but served content that fails signature or content validation.
 */
final class PluginRegistryFetchException extends \RuntimeException
{
    /**
     * @param array<string, string> $failuresByMirrorUrl reason keyed by the registry mirror URL that failed
     */
    public function __construct(public readonly array $failuresByMirrorUrl)
    {
        parent::__construct(\sprintf(
            'Failed to fetch plugins-registry.json from all %d configured mirror(s): %s',
            \count($failuresByMirrorUrl),
            implode('; ', array_map(
                static fn (string $url, string $reason): string => \sprintf('%s (%s)', $url, $reason),
                array_keys($failuresByMirrorUrl),
                $failuresByMirrorUrl,
            )),
        ));
    }
}
