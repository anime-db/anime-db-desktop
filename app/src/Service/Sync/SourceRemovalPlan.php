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

/**
 * Which sources a local deletion can also reach (issue #918), see {@see SourceRemovalPlanner}.
 *
 * $targets maps plugin id => external id of the sources whose list entry can be removed;
 * $targetNames and $kept are for the delete dialog: the names of the targets, and the sources the
 * entry stays on with the reason ('no_removal': the plugin cannot delete, 'inactive': sync is off
 * or the plugin is gone).
 */
final readonly class SourceRemovalPlan
{
    public const string REASON_NO_REMOVAL = 'no_removal';
    public const string REASON_INACTIVE = 'inactive';

    /**
     * @param array<string, string>                               $targets
     * @param list<string>                                        $targetNames
     * @param list<array{name: string, reason: non-empty-string}> $kept
     */
    public function __construct(
        public array $targets = [],
        public array $targetNames = [],
        public array $kept = [],
    ) {
    }

    public function hasTargets(): bool
    {
        return $this->targets !== [];
    }
}
