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
 * Thrown by {@see \App\Service\Market\PluginRegistryLoader::load()} when a freshly downloaded,
 * validly signed `plugins-registry.json` carries a `sequence` lower than the one already cached
 * from a previous successful load. A validly signed registry can still be an old one replayed by
 * whichever mirror served it — anti-rollback protection, not a signature problem, so it is kept
 * as its own exception rather than folded into {@see InvalidPluginRegistrySignatureException}.
 */
final class PluginRegistryRollbackException extends \RuntimeException
{
    public function __construct(
        public readonly int $rejectedSequence,
        public readonly int $lastKnownSequence,
    ) {
        parent::__construct(\sprintf(
            'Rejected plugins-registry.json with sequence %d: lower than the last known sequence %d.',
            $rejectedSequence,
            $lastKnownSequence,
        ));
    }
}
