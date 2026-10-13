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

namespace App\Twig;

use App\Repository\NotificationRepository;
use Psr\Log\LoggerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `notification_unread_count()` for the bell in base.html.twig (issue #1025): the number comes from
 * the database on every page render, never from a WebSocket event. The header is on every page, so
 * a failed read degrades to 0 instead of taking the page down.
 */
final class NotificationBellExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notification_unread_count', $this->unreadCount(...)),
        ];
    }

    public function unreadCount(): int
    {
        try {
            return $this->notifications->countUnread();
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to count unread notifications for the header bell; showing none.', ['exception' => $exception]);

            return 0;
        }
    }
}
