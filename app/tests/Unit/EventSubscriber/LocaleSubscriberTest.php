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

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\LocaleSubscriber;
use App\Service\NearestBuiltInLocale;
use App\Service\Plugin\AvailableLocalesProvider;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\Translator;

final class LocaleSubscriberTest extends TestCase
{
    public function testGetSubscribedEvents(): void
    {
        $events = LocaleSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertSame('onKernelRequest', $events[KernelEvents::REQUEST]);
    }

    public function testOnKernelRequestSetsLocaleMatchingAcceptLanguage(): void
    {
        $request = new Request();
        $request->headers->set('Accept-Language', 'ru,en;q=0.5');

        $this->dispatch($request, ['en', 'ru']);

        $this->assertSame('ru', $request->getLocale());
    }

    public function testOnKernelRequestFallsBackToFirstAvailableLocaleWhenNoneMatches(): void
    {
        $request = new Request();
        $request->headers->set('Accept-Language', 'fr');

        $this->dispatch($request, ['en', 'ru']);

        $this->assertSame('en', $request->getLocale());
    }

    public function testOnKernelRequestKeepsDefaultLocaleWhenNoLocalesConfigured(): void
    {
        $defaultLocale = (new Request())->getLocale();

        $request = new Request();
        $request->headers->set('Accept-Language', 'ru');

        $this->dispatch($request, []);

        $this->assertSame($defaultLocale, $request->getLocale());
    }

    public function testOnKernelRequestIgnoresNonMainRequest(): void
    {
        $defaultLocale = (new Request())->getLocale();

        $request = new Request();
        $request->headers->set('Accept-Language', 'ru');

        $translator = $this->createMock(Translator::class);
        $translator->expects($this->never())->method('setFallbackLocales');

        $this->dispatch($request, ['en', 'ru'], isMainRequest: false, translator: $translator);

        $this->assertSame($defaultLocale, $request->getLocale());
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function provideAcceptLanguageToFallbacks(): iterable
    {
        yield 'kk maps to the ru chain (issue #538)' => ['kk', ['ru', 'en']];
        yield 'ru maps to the ru chain' => ['ru', ['ru', 'en']];
        yield 'de maps to en only, no duplicate' => ['de', ['en']];
        yield 'en maps to en only, no duplicate' => ['en', ['en']];
    }

    /**
     * @param list<string> $expectedFallbacks
     */
    #[DataProvider('provideAcceptLanguageToFallbacks')]
    public function testOnKernelRequestSetsFallbackLocalesFromNearestBuiltInLocale(string $acceptLanguage, array $expectedFallbacks): void
    {
        $request = new Request();
        $request->headers->set('Accept-Language', $acceptLanguage);

        $translator = $this->createMock(Translator::class);
        $translator->expects($this->once())->method('setFallbackLocales')->with($expectedFallbacks);

        $this->dispatch($request, ['en', 'ru'], translator: $translator);
    }

    /**
     * Worker-mode regression (issue #538): the Translator instance is not recreated between
     * requests, so a request that would compute a different fallback chain than the previous one
     * must not inherit it.
     */
    public function testOnKernelRequestDoesNotInheritFallbackLocalesFromThePreviousRequestInWorkerMode(): void
    {
        $seen = [];

        $translator = $this->createMock(Translator::class);
        $translator->expects($this->exactly(2))->method('setFallbackLocales')
            ->willReturnCallback(function (array $fallbacks) use (&$seen): void {
                $seen[] = $fallbacks;
            });

        $subscriber = new LocaleSubscriber($this->availableLocalesProvider(['en', 'ru']), new NearestBuiltInLocale(), $translator);

        $kkRequest = new Request();
        $kkRequest->headers->set('Accept-Language', 'kk');
        $subscriber->onKernelRequest($this->requestEvent($kkRequest, true));

        $deRequest = new Request();
        $deRequest->headers->set('Accept-Language', 'de');
        $subscriber->onKernelRequest($this->requestEvent($deRequest, true));

        $this->assertSame([['ru', 'en'], ['en']], $seen);
    }

    private function requestEvent(Request $request, bool $isMainRequest): RequestEvent
    {
        $event = $this->createStub(RequestEvent::class);
        $event->method('getRequest')->willReturn($request);
        $event->method('isMainRequest')->willReturn($isMainRequest);

        return $event;
    }

    /**
     * @param list<string> $locales
     */
    private function dispatch(Request $request, array $locales, bool $isMainRequest = true, ?Translator $translator = null): void
    {
        $subscriber = new LocaleSubscriber(
            $this->availableLocalesProvider($locales),
            new NearestBuiltInLocale(),
            $translator ?? $this->createStub(Translator::class),
        );

        $subscriber->onKernelRequest($this->requestEvent($request, $isMainRequest));
    }

    /**
     * @param list<string> $coreLocales
     */
    private function availableLocalesProvider(array $coreLocales): AvailableLocalesProvider
    {
        // No plugins installed, so AvailableLocalesProvider::all() reduces to exactly $coreLocales
        // — the pluginsDir simply does not exist (see InstalledPluginsRegistryTest for the same
        // pattern).
        $registry = new InstalledPluginsRegistry(
            sys_get_temp_dir().'/anime-locale-subscriber-test-does-not-exist',
            new PluginsConfigStore(sys_get_temp_dir().'/anime-locale-subscriber-test-plugins.json'),
            new NullLogger(),
        );

        return new AvailableLocalesProvider($registry, $coreLocales);
    }
}
