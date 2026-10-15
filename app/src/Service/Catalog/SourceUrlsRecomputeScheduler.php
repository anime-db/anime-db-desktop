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

namespace App\Service\Catalog;

use App\Message\RecomputeSourceUrlsMessage;
use App\Service\Plugin\LinkRulesProvider;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a {@see RecomputeSourceUrlsMessage} when the stored fingerprint differs from the one of the
 * current link rule set (or there is none yet). Shared by `app:source-urls:check` and the end of
 * `InstalledPluginsRegistry::reconcile()`.
 */
class SourceUrlsRecomputeScheduler
{
    public function __construct(
        private readonly LinkRulesProvider $rules,
        private readonly SourceUrlsFingerprintStore $store,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @return bool whether a recompute was queued
     */
    public function checkAndSchedule(): bool
    {
        if ($this->store->get() === $this->rules->fingerprint()) {
            return false;
        }

        $this->schedule();

        return true;
    }

    public function schedule(): void
    {
        $this->bus->dispatch(new RecomputeSourceUrlsMessage());
    }
}
