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

namespace App\Service\Http;

use App\Service\ProxyConfigProvider;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Decorates a Symfony {@see HttpClientInterface} so every request goes through the app's
 * currently configured outgoing proxy (issue #327): unlike a client built once with a static
 * "proxy" option baked in via `HttpClient::create()`, this decorator reads
 * {@see ProxyConfigProvider} on every {@see self::request()} call, so a `config.json` change
 * takes effect on the very next request without restarting the FrankenPHP worker (worker-mode
 * services live across requests, so nothing gets a chance to rebuild the client between them).
 *
 * When {@see ProxyConfigProvider::getSettings()} is in `ProxyMode::None`, no "proxy"/"no_proxy"
 * option is added at all, leaving the request a direct connection. An option the caller already
 * set explicitly is never overwritten (`+=` only fills in missing keys), so callers keep the
 * ability to opt out of the proxy for a specific request.
 */
final class ProxyAwareHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ProxyConfigProvider $proxyConfigProvider,
    ) {
    }

    /**
     * Production wiring must go through this factory rather than constructing the decorator
     * directly with an arbitrary transport: a SOCKS5 proxy is only supported by ext-curl's
     * transport ({@see \Symfony\Component\HttpClient\NativeHttpClient} has no SOCKS5 support), and
     * FrankenPHP ships ext-curl, so forcing {@see CurlHttpClient} here means a manual SOCKS5
     * config never silently degrades to a direct connection.
     *
     * @param array<string, mixed> $defaultOptions
     */
    public static function create(ProxyConfigProvider $proxyConfigProvider, array $defaultOptions = []): self
    {
        return new self(new CurlHttpClient($defaultOptions), $proxyConfigProvider);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $proxyOptions = $this->proxyConfigProvider->getHttpClientOptions();
        if ($proxyOptions['proxy'] !== null) {
            $options += $proxyOptions;
        }

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self($this->client->withOptions($options), $this->proxyConfigProvider);
    }
}
