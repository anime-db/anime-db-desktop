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
use App\Service\Plugin\AvailableLocalesProvider;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

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

        $this->dispatch($request, ['en', 'ru'], isMainRequest: false);

        $this->assertSame($defaultLocale, $request->getLocale());
    }

    /**
     * @param list<string> $locales
     */
    private function dispatch(Request $request, array $locales, bool $isMainRequest = true): void
    {
        $subscriber = new LocaleSubscriber($this->availableLocalesProvider($locales));

        $event = $this->createStub(RequestEvent::class);
        $event->method('getRequest')->willReturn($request);
        $event->method('isMainRequest')->willReturn($isMainRequest);

        $subscriber->onKernelRequest($event);
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
