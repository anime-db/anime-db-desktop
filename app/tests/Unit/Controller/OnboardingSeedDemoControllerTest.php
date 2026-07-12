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

use App\Controller\OnboardingSeedDemoController;
use App\Service\Install\SampleAnimeSeeder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class OnboardingSeedDemoControllerTest extends TestCase
{
    private function createController(
        ?SampleAnimeSeeder $seeder = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?UrlGeneratorInterface $urlGenerator = null,
    ): OnboardingSeedDemoController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/');
        }

        return new OnboardingSeedDemoController(
            $seeder ?? $this->createStub(SampleAnimeSeeder::class),
            $csrfTokenManager,
            $urlGenerator,
        );
    }

    public function testSeedCallsSeederAndRedirectsHome(): void
    {
        $seeder = $this->createMock(SampleAnimeSeeder::class);
        $seeder->expects($this->once())->method('seed');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('home_index')
            ->willReturn('/');

        $controller = $this->createController(seeder: $seeder, urlGenerator: $urlGenerator);
        $request = Request::create('/onboarding/seed-demo', 'POST', ['_token' => 'token']);

        $response = $controller->seed($request);

        $this->assertSame('/', $response->getTargetUrl());
    }

    public function testSeedRejectsInvalidCsrfTokenWithoutCallingSeeder(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $seeder = $this->createMock(SampleAnimeSeeder::class);
        $seeder->expects($this->never())->method('seed');

        $controller = $this->createController(seeder: $seeder, csrfTokenManager: $csrf);
        $request = Request::create('/onboarding/seed-demo', 'POST', ['_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->seed($request);
    }
}
