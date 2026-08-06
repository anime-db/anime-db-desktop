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
use App\Service\Http\ProxyTestService;
use App\Service\ProxyConfigProvider;
use App\Service\WsPublisher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
            new ProxyConfigProvider($this->configPath),
            new ProxyTestService($httpClient ?? new MockHttpClient(new MockResponse('', ['http_code' => 200]))),
            $wsPublisher ?? $this->createStub(WsPublisher::class),
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
            ->with('proxy.updated', ['mode' => 'manual']);

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

    public function testSaveRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings/proxy', 'POST', ['_token' => 'bad', 'mode' => 'manual']);

        $this->expectException(BadRequestHttpException::class);
        $controller->save($request);

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
