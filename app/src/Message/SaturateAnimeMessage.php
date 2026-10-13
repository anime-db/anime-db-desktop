<?php

declare(strict_types=1);

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

namespace App\Message;

/**
 * Saturates one record from the source links it already has (issue #1049): see
 * {@see \App\Service\Plugin\Filler\SaturationService}. Dispatched through {@see
 * \App\Service\Plugin\Filler\SaturationQueue}, which picks the transport — `saturation`
 * (interactive, consumed by the `async` worker) or `saturation_bulk` (mass paths, consumed by the
 * plugins worker). The transport name is carried so that a handler finding the record locked can
 * re-dispatch the message into the same queue.
 *
 * $force is set only by the explicit "fill from links" button: every recognising filler is called
 * regardless of its filled_at marker.
 */
final readonly class SaturateAnimeMessage
{
    public const TRANSPORT = 'saturation';
    public const TRANSPORT_BULK = 'saturation_bulk';

    public function __construct(
        public int $animeId,
        public bool $force = false,
        public string $transport = self::TRANSPORT,
    ) {
    }

    public static function jobKey(int $animeId): string
    {
        return \sprintf('saturate:%d', $animeId);
    }
}
