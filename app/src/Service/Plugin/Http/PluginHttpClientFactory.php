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

namespace App\Service\Plugin\Http;

use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds the {@see ClientInterface} every plugin receives via DI (issue #293 item 4): plugins do
 * not open sockets themselves, they only ever see this preconfigured client, the same way they
 * never see raw filesystem paths for media (they get {@see \App\Service\Plugin\Filler\PluginMediaDownloaderInterface}
 * instead).
 *
 * {@see self::options()} is the seam for future proxy support: once the app gains a configurable
 * proxy setting, it is added to the array built there (`HttpClient::create()`'s `proxy` option),
 * and every plugin's HTTP calls start going through it transparently — no plugin code changes,
 * since plugins only ever depend on the plain PSR-18 interface, never on this factory or on
 * Symfony's own `HttpClientInterface`.
 *
 * A separate client from `app.meilisearch.http_client`/`app.plugin_media.http_client`
 * (`config/services.yaml`): those serve one specific internal purpose each (talking to the local
 * Meilisearch process, downloading a plugin-supplied cover URL) with their own tuned
 * timeouts, while this one is the general-purpose client plugin code itself makes requests with.
 */
final class PluginHttpClientFactory
{
    public function create(): ClientInterface
    {
        return new Psr18Client(HttpClient::create($this->options()));
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'timeout' => 10,
            'max_duration' => 30,
        ];
    }
}
