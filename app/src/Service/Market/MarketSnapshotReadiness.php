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

namespace App\Service\Market;

/**
 * A cached {@see MarketSnapshot} is only safe to read when it exists at all and was built for the
 * core version currently running — a snapshot left over from before an app upgrade can resolve
 * plugin compatibility against a core version nobody is running anymore. Issue #448 extracted
 * this out of {@see \App\Controller\Settings\MarketController::renderIndex()} (whose own behavior
 * does not change) so {@see \App\MessageHandler\MarketRefreshTickMessageHandler} can reuse the
 * exact same check as one of its own staleness conditions, instead of drifting from it over time.
 */
final class MarketSnapshotReadiness
{
    public function isReady(?MarketSnapshot $snapshot, ?string $coreVersion): bool
    {
        return $snapshot !== null && $snapshot->coreVersion === $coreVersion;
    }
}
