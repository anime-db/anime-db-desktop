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

namespace App\Tests\Unit\Controller;

use App\Controller\SettingsController;
use App\Service\AppSettingsProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class SettingsControllerTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-config-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->configPath)) {
            unlink($this->configPath);
        }
    }

    private function createController(
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?Environment $twig = null,
    ): SettingsController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        return new SettingsController(
            ['en', 'ru'],
            new AppSettingsProvider($this->configPath),
            $csrfTokenManager,
            $twig ?? $this->createStub(Environment::class),
        );
    }

    public function testIndexPassesAvailableLocalesAndFallsBackToFirstOneWhenConfigIsEmpty(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'currentLocale' => 'en',
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig);
        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexPassesCurrentLocaleFromConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['locale' => 'ru']));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'currentLocale' => 'ru',
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig);
        $controller->index();
    }

    public function testSetLocalePersistsChoiceAndRerendersWithoutRedirecting(): void
    {
        $controller = $this->createController();
        $request = Request::create('/settings', 'POST', ['locale' => 'ru', '_token' => 'token']);

        $response = $controller->setLocale($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertInstanceOf(Response::class, $response);

        $data = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertSame('ru', $data['locale']);
    }

    public function testSetLocaleRejectsUnknownLocale(): void
    {
        $controller = $this->createController();
        $request = Request::create('/settings', 'POST', ['locale' => 'fr', '_token' => 'token']);

        $this->expectException(BadRequestHttpException::class);
        $controller->setLocale($request);
    }

    public function testSetLocaleRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings', 'POST', ['locale' => 'ru', '_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->setLocale($request);
    }
}
