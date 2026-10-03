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

namespace App\Message;

/**
 * Recurring trigger for {@see \App\MessageHandler\MarketRefreshTickMessageHandler} (issue #448) —
 * dispatched by {@see \App\Scheduler\MarketRefreshSchedule} on every schedule tick and consumed
 * off the `scheduler_market_refresh` transport (see config/packages/messenger.yaml). Carries no
 * payload: the handler always re-derives whether a refresh is actually needed from the current
 * snapshot and config state, never from anything carried on the message itself.
 */
final readonly class MarketRefreshTickMessage
{
}
