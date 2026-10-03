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

namespace App\MessageHandler;

use App\Message\RefreshMarketSnapshotMessage;
use App\Service\Market\MarketRefreshService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a market refresh on the messenger-consumer worker (issue #440), for callers that need one
 * triggered asynchronously rather than through a console process — the render-fallback in
 * {@see \App\Controller\Settings\MarketController} and the "Check for updates" button (issue
 * #441, not yet implemented). {@see MarketRefreshService::refresh()}'s own flock() already
 * serializes this against `app:market:refresh` and any other in-flight handler run, so this
 * handler does nothing beyond delegating to it — a failed refresh just leaves the existing
 * snapshot in place (logged by the service itself) rather than throwing, since there is nothing
 * left for the queue to usefully retry that the service hasn't already accounted for.
 *
 * A third trigger, the hourly Symfony Scheduler tick (issue #448), reaches
 * {@see MarketRefreshService::refresh()} through its own handler,
 * {@see MarketRefreshTickMessageHandler}, not this one — that handler only
 * calls refresh() when the cached snapshot is actually stale, unlike this handler's unconditional
 * delegation.
 */
#[AsMessageHandler]
final class RefreshMarketSnapshotMessageHandler
{
    public function __construct(private readonly MarketRefreshService $refreshService)
    {
    }

    public function __invoke(RefreshMarketSnapshotMessage $message): void
    {
        $this->refreshService->refresh();
    }
}
