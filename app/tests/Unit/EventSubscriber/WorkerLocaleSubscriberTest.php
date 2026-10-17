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

use App\EventSubscriber\WorkerLocaleSubscriber;
use App\Service\AppSettingsProvider;
use App\Service\NearestBuiltInLocale;
use App\Service\Plugin\AvailableLocalesProvider;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Translation\Translator;

final class WorkerLocaleSubscriberTest extends TestCase
{
    public function testSubscribesToEveryReceivedMessage(): void
    {
        self::assertSame([WorkerMessageReceivedEvent::class => 'onMessageReceived'], WorkerLocaleSubscriber::getSubscribedEvents());
    }

    public function testLocaleFollowsTheSavedSettingBetweenTwoMessages(): void
    {
        $saved = 'ru';
        $settings = $this->createMock(AppSettingsProvider::class);
        $settings->method('getLocale')->willReturnCallback(static function () use (&$saved): string {
            return $saved;
        });
        $translator = new Translator('en');
        $subscriber = new WorkerLocaleSubscriber($settings, $this->available(['en', 'ru']), new NearestBuiltInLocale(), $translator);

        $subscriber->onMessageReceived($this->event());
        self::assertSame('ru', $translator->getLocale());
        self::assertSame(['ru', 'en'], $translator->getFallbackLocales());

        $saved = 'en';
        $subscriber->onMessageReceived($this->event());
        self::assertSame('en', $translator->getLocale());
        self::assertSame(['en'], $translator->getFallbackLocales());
    }

    public function testUnavailableSavedLocaleGivesWayToTheFirstAvailableOne(): void
    {
        $settings = $this->createMock(AppSettingsProvider::class);
        $settings->method('getLocale')->willReturn('ru');
        $translator = new Translator('ru');

        (new WorkerLocaleSubscriber($settings, $this->available(['en', 'de']), new NearestBuiltInLocale(), $translator))->onMessageReceived($this->event());

        self::assertSame('en', $translator->getLocale());
        self::assertSame(['en'], $translator->getFallbackLocales());
    }

    public function testMissingSavedLocaleTakesTheFirstAvailableOne(): void
    {
        $settings = $this->createMock(AppSettingsProvider::class);
        $settings->method('getLocale')->willReturn(null);
        $translator = new Translator('en');

        (new WorkerLocaleSubscriber($settings, $this->available(['ru', 'en']), new NearestBuiltInLocale(), $translator))->onMessageReceived($this->event());

        self::assertSame('ru', $translator->getLocale());
    }

    private function event(): WorkerMessageReceivedEvent
    {
        return new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'async');
    }

    /** @param list<string> $coreLocales */
    private function available(array $coreLocales): AvailableLocalesProvider
    {
        $registry = new InstalledPluginsRegistry(
            sys_get_temp_dir().'/anime-worker-locale-test-does-not-exist',
            new PluginsConfigStore(sys_get_temp_dir().'/anime-worker-locale-test-plugins.json'),
            new NullLogger(),
        );

        return new AvailableLocalesProvider($registry, $coreLocales);
    }
}
