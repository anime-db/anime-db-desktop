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

namespace App\Event;

use App\Entity\ValueObject\ProxySettings;

/**
 * Dispatched by ProxyController::save() right after a new proxy configuration is persisted via
 * ProxyConfigProvider. This is the PHP-process analogue of the "proxy.changed" WebSocket event
 * native/lifecycle/index.js subscribes to for the Chromium leg (issue #336): the qbittorrent-nox
 * WebUI is only reachable from this process, so re-applying the torrent leg (issue #347) has no
 * need to round-trip through WsPublisher/WS — a plain Symfony event and its subscriber
 * (App\EventSubscriber\TorrentProxySubscriber) give the same "settings save doesn't need to know
 * about torrent internals" decoupling in-process.
 */
final class ProxySettingsChangedEvent
{
    public function __construct(public readonly ProxySettings $settings)
    {
    }
}
