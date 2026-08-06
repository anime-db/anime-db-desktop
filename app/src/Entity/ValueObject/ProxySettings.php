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

namespace App\Entity\ValueObject;

use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;

/**
 * The app's global outgoing-proxy configuration, stored under the "proxy" key of
 * %AppData%/config.json (see ProxyConfigProvider, issue #326). Wiring this into actual HTTP
 * clients and the settings UI is out of scope for this issue.
 *
 * Enforces its own invariant rather than throwing: ProxyMode::Manual only sticks when host/port
 * are both valid, otherwise the mode is silently downgraded to ProxyMode::None. This lets the
 * provider build one straight from a hand-edited (possibly malformed) config.json without a
 * try/catch, matching how AppSettingsProvider treats a broken value as "not configured" rather
 * than a fatal error.
 */
final class ProxySettings implements \Stringable
{
    private const MIN_PORT = 1;
    private const MAX_PORT = 65535;

    public readonly ProxyMode $mode;

    public function __construct(
        ProxyMode $mode,
        public readonly ProxyProtocol $protocol,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
    ) {
        $this->mode = $mode === ProxyMode::Manual && self::isValidHost($host) && self::isValidPort($port)
            ? ProxyMode::Manual
            : ProxyMode::None;
    }

    /**
     * Proxy URL for an HTTP client's "proxy" option (e.g. Symfony HttpClient's `HttpClient::create()`),
     * or null when no proxy is configured. Username/password are percent-encoded so a ":" or "@"
     * in either cannot be mistaken for a URL delimiter.
     */
    public function toProxyUrl(): ?string
    {
        if ($this->mode !== ProxyMode::Manual) {
            return null;
        }

        $credentials = '';
        if ($this->username !== null && $this->username !== '') {
            $credentials = rawurlencode($this->username);
            if ($this->password !== null) {
                $credentials .= ':'.rawurlencode($this->password);
            }
            $credentials .= '@';
        }

        return \sprintf('%s://%s%s:%d', $this->protocol->value, $credentials, $this->host, $this->port);
    }

    /**
     * Credential-free debug representation — username/password must never end up in logs or
     * exception messages (issue #326 acceptance criteria).
     */
    public function __toString(): string
    {
        if ($this->mode !== ProxyMode::Manual) {
            return 'ProxySettings(mode=none)';
        }

        return \sprintf(
            'ProxySettings(mode=manual, protocol=%s, host=%s, port=%d)',
            $this->protocol->value,
            $this->host,
            $this->port,
        );
    }

    private static function isValidHost(?string $host): bool
    {
        return $host !== null && $host !== '';
    }

    private static function isValidPort(?int $port): bool
    {
        return $port !== null && $port >= self::MIN_PORT && $port <= self::MAX_PORT;
    }
}
