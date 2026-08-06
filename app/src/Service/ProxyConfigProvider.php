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

namespace App\Service;

use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;
use App\Entity\ValueObject\ProxySettings;

/**
 * Reads and writes the app's global outgoing-proxy setting in %AppData%/config.json, the same
 * file AppSettingsProvider reads/writes. Missing file/key/unreadable JSON or a malformed value
 * all fall back to ProxyMode::None (no proxy) rather than failing the request, the same soft
 * fallback AppSettingsProvider applies to its own keys (issue #326).
 *
 * Wiring the options {@see self::getHttpClientOptions()} returns into an actual HTTP client
 * (e.g. PluginHttpClientFactory) and exposing this through the settings UI are separate,
 * follow-up issues — this class only covers storage and reading.
 */
final class ProxyConfigProvider
{
    /**
     * Always included in the "no_proxy" option so a proxy misconfiguration can never intercept
     * traffic the app itself sends to its own local services (FrankenPHP, Meilisearch).
     */
    private const NO_PROXY_HOSTS = 'localhost,127.0.0.1,::1';

    public function __construct(private readonly string $configPath)
    {
    }

    public function getSettings(): ProxySettings
    {
        $data = $this->readConfig()['proxy'] ?? null;
        if (!\is_array($data)) {
            $data = [];
        }

        $mode = \is_string($data['mode'] ?? null) ? ProxyMode::tryFrom($data['mode']) : null;
        $protocol = \is_string($data['protocol'] ?? null) ? ProxyProtocol::tryFrom($data['protocol']) : null;
        $host = \is_string($data['host'] ?? null) ? $data['host'] : null;
        $port = \is_int($data['port'] ?? null) ? $data['port'] : null;
        $username = \is_string($data['username'] ?? null) ? $data['username'] : null;
        $password = \is_string($data['password'] ?? null) ? $data['password'] : null;

        return new ProxySettings(
            $mode ?? ProxyMode::None,
            // First transition into manual mode defaults to socks5 (issue #326).
            $protocol ?? ProxyProtocol::Socks5,
            $host,
            $port,
            $username,
            $password,
        );
    }

    /**
     * Overwrites the proxy field in place, keeping every other key (appSecret, paginationMode,
     * defaultSearchPluginId, ...) untouched — the same read-modify-write pattern
     * AppSettingsProvider uses.
     */
    public function setSettings(ProxySettings $settings): void
    {
        $config = $this->readConfig();
        $config['proxy'] = [
            'mode' => $settings->mode->value,
            'protocol' => $settings->protocol->value,
            'host' => $settings->host,
            'port' => $settings->port,
            'username' => $settings->username,
            'password' => $settings->password,
        ];

        $this->writeConfig($config);
    }

    /**
     * Options for an HTTP client (e.g. Symfony HttpClient's `HttpClient::create()`): "proxy" is
     * null when no proxy is configured, "no_proxy" always includes the app's own loopback
     * services regardless of mode.
     *
     * @return array{proxy: ?string, no_proxy: string}
     */
    public function getHttpClientOptions(): array
    {
        return [
            'proxy' => $this->getSettings()->toProxyUrl(),
            'no_proxy' => self::NO_PROXY_HOSTS,
        ];
    }

    /**
     * Writes to a temporary file in the same directory and renames it over the target path, so a
     * concurrent read from native/config.js never observes a partially written file (rename is
     * atomic within a filesystem).
     *
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): void
    {
        $directory = \dirname($this->configPath);
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $tmpPath = $directory.'/.config.json.'.uniqid('', true).'.tmp';

        $written = @file_put_contents(
            $tmpPath,
            json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );

        if ($written === false) {
            @unlink($tmpPath);

            throw new \RuntimeException(\sprintf('Failed to write temporary config file "%s".', $tmpPath));
        }

        if (!@rename($tmpPath, $this->configPath)) {
            @unlink($tmpPath);

            throw new \RuntimeException(\sprintf('Failed to rename "%s" to "%s".', $tmpPath, $this->configPath));
        }
    }

    /** @return array<string, mixed> */
    private function readConfig(): array
    {
        if (!is_file($this->configPath)) {
            return [];
        }

        $contents = file_get_contents($this->configPath);
        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);

        return \is_array($data) ? $data : [];
    }
}
