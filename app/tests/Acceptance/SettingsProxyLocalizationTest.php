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

namespace App\Tests\Acceptance;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The first test in the project to drive a request through the real kernel (issue #557) rather
 * than calling a subscriber or controller directly. `LocaleSubscriberTest::testGetSubscribedEvents()`
 * only compared the event-priority constant to itself and could never have caught the interface
 * rendering in English regardless of `Accept-Language` — {@see \App\EventSubscriber\LocaleSubscriber}
 * ran at the default priority (0), after `Symfony\Component\HttpKernel\EventListener\LocaleAwareListener`
 * (15) had already handed the translator the static `default_locale` from `framework.yaml`. This
 * asserts on the rendered HTML body, the only thing an end user actually sees, so it fails the
 * same way that bug did and would fail again if the priority ever regressed.
 *
 * `/settings/proxy` is used because it renders through a plain `|trans` (unlike
 * `settings/index.html.twig`, which passes the locale explicitly and would not have caught this)
 * and returns 200 with no fixture setup in the test environment.
 */
final class SettingsProxyLocalizationTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAcceptLanguageToExpectedHeading(): iterable
    {
        yield 'ru' => ['ru', 'Прокси-сервер'];
        yield 'en' => ['en', 'Proxy server'];
    }

    #[DataProvider('provideAcceptLanguageToExpectedHeading')]
    public function testSettingsProxyPageRendersInTheNegotiatedLocale(string $acceptLanguage, string $expectedHeading): void
    {
        $kernel = self::bootKernel();

        $request = Request::create('/settings/proxy');
        $request->headers->set('Accept-Language', $acceptLanguage);

        $response = $kernel->handle($request);

        $body = (string) $response->getContent();

        self::assertStringContainsString(
            '<h1>'.$expectedHeading.'</h1>',
            $body,
            'The page heading was not translated into the negotiated locale.',
        );
        self::assertStringContainsString(
            '<html lang="'.$acceptLanguage.'"',
            $body,
            'The <html lang> attribute does not match the negotiated locale.',
        );
    }
}
