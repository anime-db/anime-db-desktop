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

use App\Message\MarketRefreshTickMessage;
use App\Service\AppConfigStore;
use App\Service\Market\MarketRefreshService;
use App\Service\Market\MarketSnapshotCache;
use App\Service\Market\MarketSnapshotReadiness;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs on every {@see \App\Scheduler\MarketRefreshSchedule} tick (issue #448) and decides whether
 * the cached market snapshot is stale enough to warrant actually calling
 * {@see MarketRefreshService::refresh()} — unlike {@see RefreshMarketSnapshotMessageHandler},
 * which always delegates unconditionally, this handler is the gate itself. Nothing here writes
 * any state of its own: a decision to refresh still goes through the service's own flock() and
 * timestamp bookkeeping, so a `true` return from refresh() while a concurrent refresh is already
 * in flight (see that method's docblock) is treated the same as any other tick that decided not
 * to refresh — there is nothing new to record either way.
 *
 * The snapshot needs a refresh when any of the following holds:
 * - {@see MarketSnapshotReadiness::isReady()} says no (no cached snapshot at all, or one built
 *   for a different `%app.core_version%` — the same check {@see \App\Controller\Settings\MarketController::renderIndex()}
 *   makes, shared here rather than duplicated);
 * - {@see MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT} is missing, fails to parse, is older
 *   than {@see self::MAX_LAST_REFRESH_AGE_SECONDS}, or lies in the future (a clock turned back).
 *
 * Deliberately reads {@see MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT} (the last *success*),
 * not {@see MarketRefreshService::CONFIG_KEY_LAST_REFRESH_ATTEMPT_AT} (the last attempt,
 * success or failure alike) — gating on the attempt marker would let a single offline failure
 * silence every tick for a full day, even though nothing ever actually refreshed.
 */
#[AsMessageHandler]
final class MarketRefreshTickMessageHandler
{
    /**
     * How stale {@see MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT} is allowed to get before
     * this handler forces a refresh regardless of {@see MarketSnapshotReadiness} — see the class
     * docblock.
     */
    private const int MAX_LAST_REFRESH_AGE_SECONDS = 24 * 60 * 60;

    public function __construct(
        private readonly MarketSnapshotCache $snapshotCache,
        private readonly MarketSnapshotReadiness $snapshotReadiness,
        private readonly AppConfigStore $configStore,
        private readonly MarketRefreshService $refreshService,
        private readonly ?string $coreVersion,
    ) {
    }

    public function __invoke(MarketRefreshTickMessage $message): void
    {
        if ($this->snapshotNeedsRefresh()) {
            $this->refreshService->refresh();
        }
    }

    private function snapshotNeedsRefresh(): bool
    {
        if (!$this->snapshotReadiness->isReady($this->snapshotCache->load(), $this->coreVersion)) {
            return true;
        }

        return $this->isLastRefreshStale();
    }

    private function isLastRefreshStale(): bool
    {
        $lastRefreshAt = $this->configStore->read()[MarketRefreshService::CONFIG_KEY_LAST_REFRESH_AT] ?? null;
        if (!\is_string($lastRefreshAt)) {
            return true;
        }

        $lastRefreshAtDate = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $lastRefreshAt);
        if ($lastRefreshAtDate === false) {
            return true;
        }

        $now = new \DateTimeImmutable();
        if ($lastRefreshAtDate > $now) {
            return true;
        }

        return ($now->getTimestamp() - $lastRefreshAtDate->getTimestamp()) > self::MAX_LAST_REFRESH_AGE_SECONDS;
    }
}
