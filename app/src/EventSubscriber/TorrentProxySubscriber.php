<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Event\ProxySettingsChangedEvent;
use App\Service\Qbittorrent\TorrentProxySynchronizer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Re-applies the proxy to the qbittorrent-nox sidecar whenever ProxyController::save() dispatches
 * ProxySettingsChangedEvent — the torrent leg's counterpart to native/lifecycle/index.js's
 * "proxy.changed" WS listener, which does the same for the Chromium session (issue #347).
 *
 * TorrentProxyApplyException (fail-closed SOCKS5 apply failures) is intentionally left
 * unhandled here: ProxyController catches it to turn it into a visible error on the settings
 * page, rather than this subscriber silently swallowing a failure that must stay loud.
 */
final class TorrentProxySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TorrentProxySynchronizer $synchronizer)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProxySettingsChangedEvent::class => 'onProxySettingsChanged',
        ];
    }

    public function onProxySettingsChanged(ProxySettingsChangedEvent $event): void
    {
        $this->synchronizer->apply($event->settings);
    }
}
