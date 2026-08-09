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

namespace App\Controller\Settings;

use App\Entity\Enum\ProxyMode;
use App\Entity\Enum\ProxyProtocol;
use App\Entity\ValueObject\ProxySettings;
use App\Event\ProxySettingsChangedEvent;
use App\Service\Exception\TorrentProxyApplyException;
use App\Service\Http\ProxyTestService;
use App\Service\ProxyConfigProvider;
use App\Service\WsPublisher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * User-facing settings page for the app's outgoing proxy (issue #328), the missing piece between
 * ProxyConfigProvider's storage (issue #326) and ProxyAwareHttpClient's usage of it (issue #327).
 * This is the single writer of the "proxy" config.json key from the UI: every save() goes
 * through ProxyConfigProvider::setSettings(), which read-modify-writes the file so every other
 * key stays untouched.
 */
final class ProxyController
{
    /**
     * Backend event name published on save() and consumed by native/lifecycle/index.js over
     * /ws to call proxy.applyProxy() on the live Chromium session without an app restart. Keep
     * this string in sync with PROXY_CHANGED_EVENT in native/proxy.js — a mismatch here breaks
     * live-apply silently (issue #336), which is why ProxyControllerTest cross-checks both
     * sides against each other.
     */
    public const PROXY_CHANGED_EVENT = 'proxy.changed';

    public function __construct(
        private readonly ProxyConfigProvider $proxyConfigProvider,
        private readonly ProxyTestService $proxyTestService,
        private readonly WsPublisher $wsPublisher,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/settings/proxy', name: 'settings_proxy_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderIndex($this->proxyConfigProvider->getSettings());
    }

    #[Route('/settings/proxy', name: 'settings_proxy_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->assertValidCsrfToken('settings_proxy_save', $request);

        $settings = $this->buildSettingsFromRequest($request);
        $this->proxyConfigProvider->setSettings($settings);

        // Native layer already listens to every /ws event (see native/lifecycle/index.js); only
        // the fact that the proxy changed is published here, never host/port/credentials, which
        // stay solely in config.json (issue #328 acceptance: no credentials leave the process
        // through anything but the config file itself).
        $this->wsPublisher->publish(self::PROXY_CHANGED_EVENT, ['mode' => $settings->mode->value]);

        // Torrent leg's counterpart to the WS event above (issue #347): TorrentProxySubscriber
        // re-applies the proxy to qbittorrent-nox in-process. A SOCKS5 apply failure is fail-closed
        // (torrents stay paused) and must surface as a visible error here rather than a silent
        // direct fallback or an unhandled 500.
        try {
            $this->eventDispatcher->dispatch(new ProxySettingsChangedEvent($settings));
        } catch (TorrentProxyApplyException) {
            return $this->renderIndex($settings, saved: true, torrentProxyError: true);
        }

        return $this->renderIndex($settings, saved: true);
    }

    /**
     * HTMX endpoint (see settings/proxy/index.html.twig): tests the proxy values currently in
     * the form, including unsaved ones, against $testUrl with its own one-off HTTP client — the
     * saved config.json proxy and the browser's own network stack are never involved.
     */
    #[Route('/settings/proxy/test', name: 'settings_proxy_test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        $this->assertValidCsrfToken('settings_proxy_test', $request, '_token_test');

        $settings = $this->buildSettingsFromRequest($request);
        $url = (string) $request->request->get('test_url', '');

        $result = $this->proxyTestService->test($settings, $url);

        return new Response($this->twig->render('settings/proxy/_test_result.html.twig', [
            'result' => $result,
        ]));
    }

    private function renderIndex(ProxySettings $settings, bool $saved = false, bool $torrentProxyError = false): Response
    {
        return new Response($this->twig->render('settings/proxy/index.html.twig', [
            'settings' => $settings,
            'saved' => $saved,
            'torrentProxyError' => $torrentProxyError,
        ]));
    }

    private function buildSettingsFromRequest(Request $request): ProxySettings
    {
        $mode = ProxyMode::tryFrom((string) $request->request->get('mode', '')) ?? ProxyMode::None;
        $protocol = ProxyProtocol::tryFrom((string) $request->request->get('protocol', '')) ?? ProxyProtocol::Socks5;

        $host = (string) $request->request->get('host', '');
        $portRaw = (string) $request->request->get('port', '');
        $username = (string) $request->request->get('username', '');
        $password = (string) $request->request->get('password', '');

        return new ProxySettings(
            $mode,
            $protocol,
            $host === '' ? null : $host,
            ctype_digit($portRaw) ? (int) $portRaw : null,
            $username === '' ? null : $username,
            $password === '' ? null : $password,
        );
    }

    private function assertValidCsrfToken(string $tokenId, Request $request, string $field = '_token'): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get($field));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
