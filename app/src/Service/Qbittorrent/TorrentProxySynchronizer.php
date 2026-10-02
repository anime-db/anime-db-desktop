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

namespace App\Service\Qbittorrent;

use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;
use App\Entity\ValueObject\ProxySettings;
use App\Service\Exception\QbittorrentClientException;
use App\Service\Exception\TorrentProxyApplyException;

/**
 * Re-applies the app's global proxy setting to an already-running qbittorrent-nox sidecar
 * (issue #347) — the runtime counterpart of qbittorrent.js's seedConfig(), which only covers the
 * proxy qbittorrent-nox is spawned with (issue #345).
 *
 * Protocol-dependent behaviour (deliberate, not a gap):
 * - SOCKS5 is the only protocol libtorrent can tunnel P2P (peer/tracker/DHT/UDP) traffic through,
 *   so it is applied fail-closed: {@see applySocks5FailClosed()} stops every torrent BEFORE
 *   touching the proxy, applies the new settings, reads them back to confirm qbittorrent-nox
 *   actually accepted them, and only starts them again once that confirmation succeeds. Any
 *   failure along the way — the stop call, the apply call, or a readback that doesn't match —
 *   leaves every torrent stopped and throws, so egress never resumes unproxied by accident.
 * - HTTP does not cover P2P traffic at the protocol level (it has no notion of a UDP/tracker
 *   tunnel), so the torrent leg is intentionally left running direct rather than blocked — the
 *   settings page already warns the user about this via http_protocol_hint (issue #328).
 * - No proxy configured (ProxyMode::None) also runs direct.
 */
final class TorrentProxySynchronizer
{
    /**
     * Preference keys read back after a SOCKS5 apply to confirm qbittorrent-nox actually
     * accepted them — this is the "verify the route, not just that the WebUI answered" check
     * issue #347 asks for. Credentials are deliberately excluded: qbittorrent-nox's own
     * /api/v2/app/preferences response never echoes proxy_password back, so comparing it would
     * always (falsely) fail.
     *
     * Names match the qBittorrent 5.x WebUI API (verified against the shipped 5.2.3, see
     * scripts/versions.json, and the appcontroller.cpp source for that tag): the pre-5.0 names
     * `proxy_hostnames`/`proxy_tracker_connections` no longer exist.
     */
    private const CONFIRMED_KEYS = ['proxy_type', 'proxy_ip', 'proxy_port', 'proxy_hostname_lookup', 'proxy_bittorrent', 'proxy_peer_connections'];

    public function __construct(private readonly QbittorrentClient $client)
    {
    }

    public function apply(ProxySettings $settings): void
    {
        if ($settings->mode === ProxyMode::Manual && $settings->protocol === ProxyProtocol::Socks5) {
            $this->applySocks5FailClosed($settings);

            return;
        }

        // HTTP proxy or no proxy at all: the torrent leg always runs direct, never blocked.
        $this->client->setPreferences(self::directPreferences());
    }

    private function applySocks5FailClosed(ProxySettings $settings): void
    {
        $preferences = self::socks5Preferences($settings);

        try {
            // Stop BEFORE touching the proxy: a torrent that is already connected must not keep
            // exchanging peer/tracker traffic unproxied while the new setting is being applied.
            $this->client->stop('all');
            $this->client->setPreferences($preferences);
            $this->confirmApplied($preferences);
            // Start is inside the same try/catch: a failure here means the proxy is confirmed
            // but torrents did not start again, which must surface as the same visible fail-closed
            // error rather than an unhandled exception — torrents simply stay stopped either way.
            $this->client->start('all');
        } catch (QbittorrentClientException $exception) {
            throw new TorrentProxyApplyException('Failed to apply the SOCKS5 proxy to qbittorrent-nox; torrent egress stays paused (fail-closed).', previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function confirmApplied(array $expected): void
    {
        $actual = $this->client->getPreferences();

        foreach (self::CONFIRMED_KEYS as $key) {
            if (($actual[$key] ?? null) !== $expected[$key]) {
                throw new TorrentProxyApplyException(\sprintf('qbittorrent-nox did not confirm the SOCKS5 proxy preference "%s"; torrent egress stays paused (fail-closed).', $key));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function socks5Preferences(ProxySettings $settings): array
    {
        $preferences = [
            // Matches the string enum values Net::ProxyType serialises to, the same convention
            // qbittorrent.js's seedConfig() uses for the boot-time Network\Proxy\Type ini key.
            'proxy_type' => 'SOCKS5',
            'proxy_ip' => $settings->host,
            'proxy_port' => $settings->port,
            // DNS-leak guards (issue #347 acceptance): hostnames are resolved on the proxy side
            // (proxy_hostname_lookup), peer connections are routed through it
            // (proxy_peer_connections), and tracker/announce traffic is covered by the
            // "BitTorrent purposes" toggle (proxy_bittorrent) — without these, only some torrent
            // traffic would be proxied while the rest (and DNS) leaks direct.
            'proxy_hostname_lookup' => true,
            'proxy_peer_connections' => true,
            'proxy_bittorrent' => true,
        ];

        $hasAuth = $settings->username !== null && $settings->username !== '';
        $preferences['proxy_auth_enabled'] = $hasAuth;
        if ($hasAuth) {
            $preferences['proxy_username'] = $settings->username;
            $preferences['proxy_password'] = $settings->password ?? '';
        }

        return $preferences;
    }

    /**
     * @return array<string, mixed>
     */
    private static function directPreferences(): array
    {
        return [
            'proxy_type' => 'None',
            'proxy_hostname_lookup' => false,
            'proxy_peer_connections' => false,
            'proxy_bittorrent' => false,
        ];
    }
}
