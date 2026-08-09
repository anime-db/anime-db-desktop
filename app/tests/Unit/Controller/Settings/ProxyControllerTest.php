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

namespace App\Tests\Unit\Controller\Settings;

use App\Controller\Settings\ProxyController;
use App\Entity\Enum\ProxyMode;
use App\Entity\ValueObject\ProxySettings;
use App\Event\ProxySettingsChangedEvent;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Exception\TorrentProxyApplyException;
use App\Service\Http\ProxyTestService;
use App\Service\ProxyConfigProvider;
use App\Service\WsPublisher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class ProxyControllerTest extends KernelTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-proxy-controller-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->configPath)) {
            unlink($this->configPath);
        }
    }

    /**
     * ProxyConfigProvider and ProxyTestService are both final, so they cannot be mocked — a real
     * ProxyConfigProvider backed by a throwaway config.json, and a real ProxyTestService backed
     * by a MockHttpClient, stand in for them instead.
     *
     * @param ?array<string, mixed> $existingConfig
     */
    private function createController(
        ?array $existingConfig = null,
        ?MockHttpClient $httpClient = null,
        ?WsPublisher $wsPublisher = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?Environment $twig = null,
    ): ProxyController {
        if ($existingConfig !== null) {
            file_put_contents($this->configPath, json_encode($existingConfig));
        }

        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        return new ProxyController(
            new ProxyConfigProvider(new AppConfigStore($this->configPath)),
            new ProxyTestService($httpClient ?? new MockHttpClient(new MockResponse('', ['http_code' => 200]))),
            new AppSettingsProvider(new AppConfigStore($this->configPath)),
            $wsPublisher ?? $this->createStub(WsPublisher::class),
            $eventDispatcher ?? $this->createStub(EventDispatcherInterface::class),
            $csrfTokenManager,
            $twig ?? $this->createStub(Environment::class),
        );
    }

    public function testIndexRendersCurrentlySavedSettings(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/proxy/index.html.twig', $this->callback(
                static function (array $params): bool {
                    $settings = $params['settings'];

                    return $settings instanceof ProxySettings
                        && $settings->mode === ProxyMode::Manual
                        && $settings->host === 'proxy.example'
                        && $params['saved'] === false;
                },
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(
            existingConfig: ['proxy' => ['mode' => 'manual', 'protocol' => 'socks5', 'host' => 'proxy.example', 'port' => 1080]],
            twig: $twig,
        );

        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSavePersistsSettingsAndPreservesOtherConfigKeys(): void
    {
        $controller = $this->createController(existingConfig: ['appSecret' => 'keep-me', 'locale' => 'ru']);

        $request = Request::create('/settings/proxy', 'POST', [
            '_token' => 'token',
            'mode' => 'manual',
            'protocol' => 'http',
            'host' => 'proxy.example',
            'port' => '3128',
            'username' => 'alice',
            'password' => 'p4ss',
        ]);

        $controller->save($request);

        $stored = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertSame('keep-me', $stored['appSecret']);
        $this->assertSame('ru', $stored['locale']);
        $this->assertSame([
            'mode' => 'manual',
            'protocol' => 'http',
            'host' => 'proxy.example',
            'port' => 3128,
            'username' => 'alice',
            'password' => 'p4ss',
        ], $stored['proxy']);
    }

    public function testSavePublishesWsNotificationForNativeLayerWithoutCredentials(): void
    {
        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->once())
            ->method('publish')
            ->with(ProxyController::PROXY_CHANGED_EVENT, ['mode' => 'manual']);

        $controller = $this->createController(wsPublisher: $wsPublisher);

        $request = Request::create('/settings/proxy', 'POST', [
            '_token' => 'token',
            'mode' => 'manual',
            'protocol' => 'http',
            'host' => 'proxy.example',
            'port' => '3128',
            'username' => 'alice',
            'password' => 'p4ss',
        ]);

        $controller->save($request);
    }

    /**
     * Torrent leg's counterpart to the WS notification above (issue #347): save() must dispatch
     * ProxySettingsChangedEvent carrying the just-saved settings so TorrentProxySubscriber can
     * re-apply them to qbittorrent-nox in-process.
     */
    public function testSaveDispatchesProxySettingsChangedEventWithSavedSettings(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                static function (ProxySettingsChangedEvent $event): bool {
                    return $event->settings->mode === ProxyMode::Manual
                        && $event->settings->host === 'proxy.example'
                        && $event->settings->port === 3128;
                },
            ))
            ->willReturnArgument(0);

        $controller = $this->createController(eventDispatcher: $eventDispatcher);

        $request = Request::create('/settings/proxy', 'POST', [
            '_token' => 'token',
            'mode' => 'manual',
            'protocol' => 'http',
            'host' => 'proxy.example',
            'port' => '3128',
        ]);

        $response = $controller->save($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A fail-closed SOCKS5 apply failure must never bubble up as an unhandled 500 — the settings
     * themselves are already saved successfully by this point, so save() renders the page again
     * with a visible torrent-proxy error flag instead (issue #347 acceptance: a partial apply
     * failure must be a visible error, not a silent success page).
     */
    public function testSaveSurfacesTorrentProxyErrorWithoutThrowingWhenFailClosedApplyFails(): void
    {
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willThrowException(new TorrentProxyApplyException('boom'));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/proxy/index.html.twig', $this->callback(
                static fn (array $params): bool => $params['saved'] === true && $params['torrentProxyError'] === true,
            ))
            ->willReturn('<html></html>');

        $controller = $this->createController(eventDispatcher: $eventDispatcher, twig: $twig);

        $request = Request::create('/settings/proxy', 'POST', [
            '_token' => 'token',
            'mode' => 'manual',
            'protocol' => 'socks5',
            'host' => 'proxy.example',
            'port' => '1080',
        ]);

        $response = $controller->save($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * PHP and native/proxy.js each declare the event name as their own literal (they run in
     * separate processes and cannot share a constant), so nothing stops the two from drifting
     * apart again the way they did before issue #336 ('proxy.updated' vs 'proxy.changed'). This
     * test closes that gap by reading native/proxy.js's PROXY_CHANGED_EVENT literal straight out
     * of its source and comparing it against the PHP constant actually published in save().
     */
    public function testProxyChangedEventNameMatchesNativeProxyModuleContract(): void
    {
        $nativeProxySource = (string) file_get_contents(\dirname(__DIR__, 5).'/native/proxy.js');

        $matched = preg_match("/const PROXY_CHANGED_EVENT = '([^']+)';/", $nativeProxySource, $matches);

        $this->assertSame(1, $matched, 'native/proxy.js must declare a PROXY_CHANGED_EVENT constant.');
        $this->assertSame(
            ProxyController::PROXY_CHANGED_EVENT,
            $matches[1],
            'ProxyController::PROXY_CHANGED_EVENT must match native/proxy.js PROXY_CHANGED_EVENT — a mismatch silently breaks live proxy apply (issue #336).',
        );
    }

    public function testIncomingConnectionsPersistsAndPublishesEnableWhenDirectMode(): void
    {
        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->once())
            ->method('publish')
            ->with(ProxyController::FIREWALL_RULE_CHANGED_EVENT, ['enabled' => true]);

        $controller = $this->createController(
            existingConfig: ['proxy' => ['mode' => 'none', 'protocol' => 'socks5']],
            wsPublisher: $wsPublisher,
        );

        $request = Request::create('/settings/proxy/incoming-connections', 'POST', ['_token' => 'token', 'enabled' => '1']);
        $response = $controller->incomingConnections($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertTrue($data['incomingConnectionsAllowed']);
    }

    public function testIncomingConnectionsPersistsAndPublishesDisable(): void
    {
        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->once())
            ->method('publish')
            ->with(ProxyController::FIREWALL_RULE_CHANGED_EVENT, ['enabled' => false]);

        $controller = $this->createController(
            existingConfig: ['incomingConnectionsAllowed' => true, 'proxy' => ['mode' => 'none', 'protocol' => 'socks5']],
            wsPublisher: $wsPublisher,
        );

        $request = Request::create('/settings/proxy/incoming-connections', 'POST', ['_token' => 'token']);
        $controller->incomingConnections($request);

        $data = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertFalse($data['incomingConnectionsAllowed']);
    }

    /**
     * The disabled checkbox in the template already prevents this in the browser, but the
     * controller must reject it too (defense in depth) — SOCKS5 makes the torrent client
     * unreachable from the outside regardless of any firewall rule (issue #361).
     */
    public function testIncomingConnectionsRejectsEnableWhenSocks5Active(): void
    {
        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->never())->method('publish');

        $controller = $this->createController(
            existingConfig: ['proxy' => ['mode' => 'manual', 'protocol' => 'socks5', 'host' => 'proxy.example', 'port' => 1080]],
            wsPublisher: $wsPublisher,
        );

        $request = Request::create('/settings/proxy/incoming-connections', 'POST', ['_token' => 'token', 'enabled' => '1']);
        $controller->incomingConnections($request);

        $this->assertFalse((new AppSettingsProvider(new AppConfigStore($this->configPath)))->getIncomingConnectionsAllowed());
    }

    /**
     * Turning the toggle back *off* must stay possible even under SOCKS5, so a rule added while
     * still in direct mode can be cleaned up after switching proxy modes.
     */
    public function testIncomingConnectionsAllowsDisableWhenSocks5Active(): void
    {
        $wsPublisher = $this->createMock(WsPublisher::class);
        $wsPublisher->expects($this->once())
            ->method('publish')
            ->with(ProxyController::FIREWALL_RULE_CHANGED_EVENT, ['enabled' => false]);

        $controller = $this->createController(
            existingConfig: [
                'incomingConnectionsAllowed' => true,
                'proxy' => ['mode' => 'manual', 'protocol' => 'socks5', 'host' => 'proxy.example', 'port' => 1080],
            ],
            wsPublisher: $wsPublisher,
        );

        $request = Request::create('/settings/proxy/incoming-connections', 'POST', ['_token' => 'token']);
        $controller->incomingConnections($request);

        $data = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertFalse($data['incomingConnectionsAllowed']);
    }

    public function testIncomingConnectionsRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings/proxy/incoming-connections', 'POST', ['_token' => 'bad', 'enabled' => '1']);

        $this->expectException(BadRequestHttpException::class);
        $controller->incomingConnections($request);
    }

    /**
     * Same drift protection as testProxyChangedEventNameMatchesNativeProxyModuleContract, for the
     * incoming-connections leg introduced by issue #361: native/firewall.js declares its own
     * literal, and nothing but a test closes the gap between the two.
     */
    public function testFirewallRuleChangedEventNameMatchesNativeFirewallModuleContract(): void
    {
        $nativeFirewallSource = (string) file_get_contents(\dirname(__DIR__, 5).'/native/firewall.js');

        $matched = preg_match("/const FIREWALL_RULE_CHANGED_EVENT = '([^']+)';/", $nativeFirewallSource, $matches);

        $this->assertSame(1, $matched, 'native/firewall.js must declare a FIREWALL_RULE_CHANGED_EVENT constant.');
        $this->assertSame(
            ProxyController::FIREWALL_RULE_CHANGED_EVENT,
            $matches[1],
            'ProxyController::FIREWALL_RULE_CHANGED_EVENT must match native/firewall.js FIREWALL_RULE_CHANGED_EVENT — a mismatch silently breaks the incoming-connections toggle (issue #361).',
        );
    }

    public function testSaveRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings/proxy', 'POST', ['_token' => 'bad', 'mode' => 'manual']);

        try {
            $controller->save($request);
            $this->fail('Expected BadRequestHttpException was not thrown.');
        } catch (BadRequestHttpException) {
        }

        $this->assertFalse(is_file($this->configPath));
    }

    public function testTestActionUsesFormValuesEvenWhenDifferentFromSavedSettings(): void
    {
        $capturedOptions = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
            $capturedOptions = $options;

            return new MockResponse('', ['http_code' => 200]);
        }, 'https://anime-db.org');

        $controller = $this->createController(
            existingConfig: ['proxy' => ['mode' => 'manual', 'protocol' => 'socks5', 'host' => 'saved-proxy.example', 'port' => 1080]],
            httpClient: $httpClient,
        );

        $request = Request::create('/settings/proxy/test', 'POST', [
            '_token_test' => 'token',
            'mode' => 'manual',
            'protocol' => 'http',
            'host' => 'form-proxy.example',
            'port' => '9050',
            'test_url' => 'https://anime-db.org',
        ]);

        $controller->test($request);

        $this->assertIsArray($capturedOptions);
        $this->assertSame('http://form-proxy.example:9050', $capturedOptions['proxy'] ?? null);
    }

    /**
     * Uses the real Twig service (not a stub) so the assertions below exercise the actual
     * _test_result.html.twig rendering path — a stubbed Environment::render() would return ''
     * and make every assertStringNotContainsString() pass trivially without proving anything.
     */
    public function testTestActionResponseNeverContainsHostPortOrCredentials(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $httpClient = new MockHttpClient(static function (): never {
            throw new TransportException('Failed to connect to secret-proxy.internal port 1234: Connection refused');
        });

        $controller = $this->createController(httpClient: $httpClient, twig: $twig);

        $request = Request::create('/settings/proxy/test', 'POST', [
            '_token_test' => 'token',
            'mode' => 'manual',
            'protocol' => 'http',
            'host' => 'secret-proxy.internal',
            'port' => '1234',
            'username' => 'alice',
            'password' => 'super-secret',
            'test_url' => 'https://anime-db.org',
        ]);

        $response = $controller->test($request);

        $body = (string) $response->getContent();
        $this->assertNotSame('', $body);
        $this->assertStringNotContainsString('secret-proxy.internal', $body);
        $this->assertStringNotContainsString('1234', $body);
        $this->assertStringNotContainsString('alice', $body);
        $this->assertStringNotContainsString('super-secret', $body);
        $this->assertStringNotContainsString('Connection refused', $body);
    }

    public function testTestActionDoesNotPersistAnything(): void
    {
        $controller = $this->createController();

        $request = Request::create('/settings/proxy/test', 'POST', [
            '_token_test' => 'token',
            'mode' => 'manual',
            'protocol' => 'http',
            'host' => 'form-proxy.example',
            'port' => '9050',
            'test_url' => 'https://anime-db.org',
        ]);

        $controller->test($request);

        $this->assertFalse(is_file($this->configPath));
    }

    public function testTestActionRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings/proxy/test', 'POST', ['_token_test' => 'bad', 'test_url' => 'https://anime-db.org']);

        $this->expectException(BadRequestHttpException::class);
        $controller->test($request);
    }

    /**
     * hx-include="#proxy-settings-form, #proxy-test-form" serializes both forms into one POST
     * body, so the save form's "_token" and the test form's "_token_test" both arrive together.
     * The test action must validate the "settings_proxy_test" CSRF token strictly from the
     * "_token_test" field and ignore the save form's "_token", regardless of field order.
     */
    public function testTestActionValidatesTestTokenFieldWhenSaveFormTokenAlsoPresent(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $token): bool => $token->getId() === 'settings_proxy_test' && $token->getValue() === 'valid-test-token',
        );

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings/proxy/test', 'POST', [
            '_token' => 'save-form-token',
            '_token_test' => 'valid-test-token',
            'mode' => 'manual',
            'test_url' => 'https://anime-db.org',
        ]);

        $response = $controller->test($request);

        $this->assertSame(200, $response->getStatusCode());
    }
}
