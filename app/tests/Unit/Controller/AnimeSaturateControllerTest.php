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

namespace App\Tests\Unit\Controller;

use App\Controller\AnimeSaturateController;
use App\Entity\TvAnime;
use App\Tests\Support\RecordingSaturationBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnimeSaturateControllerTest extends TestCase
{
    private RecordingSaturationBus $bus;

    private function controller(bool $csrfValid): AnimeSaturateController
    {
        $this->bus = new RecordingSaturationBus();
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $csrfValid && $token->getId() === 'anime_saturate_7' && $token->getValue() === 'token');
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $name, array $params): string => '/'.$name.'/'.$params['id']);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AnimeSaturateController($this->bus->queue(), $csrf, $urls, $translator);
    }

    private function anime(): TvAnime
    {
        $anime = new TvAnime();
        (new \ReflectionProperty(\App\Entity\Anime::class, 'id'))->setValue($anime, 7);

        return $anime;
    }

    private function request(string $token): Request
    {
        $request = new Request(request: ['_token' => $token]);
        $session = $this->createStub(FlashBagAwareSessionInterface::class);
        $session->method('getFlashBag')->willReturn(new FlashBag());
        $request->setSession($session);

        return $request;
    }

    public function testQueuesAForcedSaturationOfThatRecordIntoTheInteractiveQueueAndRedirectsBack(): void
    {
        $response = $this->controller(true)->saturate($this->anime(), $this->request('token'));

        $this->assertSame([['id' => 7, 'force' => true, 'transport' => 'saturation']], $this->bus->saturations());
        $this->assertSame('/anime_show/7', $response->headers->get('Location'));
    }

    public function testAnInvalidCsrfTokenQueuesNothing(): void
    {
        $controller = $this->controller(true);

        try {
            $controller->saturate($this->anime(), $this->request('wrong'));
            $this->fail('A bad CSRF token must be rejected.');
        } catch (BadRequestHttpException) {
        }

        $this->assertSame([], $this->bus->saturations());
    }
}
