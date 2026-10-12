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

namespace App\Tests\Unit\Service\Notification;

use App\Service\AppSettingsProvider;
use App\Service\NearestBuiltInLocale;
use App\Service\Notification\NotificationKindRegistry;
use App\Service\Notification\NotificationRenderer;
use App\Service\Notification\NotificationSubjectLinker;
use App\Tests\Support\NotificationTestKit;
use App\Tests\Support\NotificationTestLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class NotificationRendererTest extends TestCase
{
    private const DATE = '2026-10-09 12:00:00';

    /**
     * @param array<string, array<string, string>>|null $messages
     */
    private function renderer(?string $locale, ?array $messages = null, ?NotificationTestLogger $logger = null, ?NotificationSubjectLinker $linker = null): NotificationRenderer
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('array', new ArrayLoader());
        foreach ($messages ?? NotificationTestKit::messages() as $messageLocale => $catalogue) {
            $translator->addResource('array', $catalogue, $messageLocale);
        }

        $settings = $this->createStub(AppSettingsProvider::class);
        $settings->method('getLocale')->willReturn($locale);

        $linker ??= $this->createStub(NotificationSubjectLinker::class);

        return new NotificationRenderer(
            new NotificationKindRegistry([new NotificationTestKit()]),
            $translator,
            $settings,
            new NearestBuiltInLocale(),
            $linker,
            $logger ?? NotificationTestKit::logger(),
        );
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function render(NotificationRenderer $renderer, string $kind = 'test_event', ?array $params = null, ?string $type = null, ?string $id = null): \App\Service\Notification\RenderedNotification
    {
        return $renderer->render(
            $kind,
            $params ?? ['name' => 'Naruto', 'count' => 3, 'reason' => 'disk_space'],
            $type,
            $id,
            new \DateTimeImmutable(self::DATE),
        );
    }

    public function testRendersInTheStoredSettingsLocaleOutsideAnHttpRequest(): void
    {
        $rendered = $this->render($this->renderer('ru'));

        $this->assertSame('Тестовое событие: Naruto', $rendered->title);
        $this->assertSame('Элементов: 3, причина: нет места на диске', $rendered->body);
        $this->assertFalse($rendered->degraded);
        $this->assertNull($rendered->url);
    }

    public function testDoesNotFoldAPluginLocaleThroughResolve(): void
    {
        $messages = NotificationTestKit::messages();
        $messages['uk'] = [
            'notification.test_event.title' => 'Подія: %name%',
            'notification.test_event.body' => 'Кількість: %count%, причина: %reason%',
            'notification.test_event.reason.disk_space' => 'немає місця',
            'notification.test_event.reason.network' => 'мережа',
        ];

        $rendered = $this->render($this->renderer('uk', $messages));

        $this->assertSame('Подія: Naruto', $rendered->title);
    }

    public function testPlaceholderLookingParameterValueIsNotDegraded(): void
    {
        $rendered = $this->render($this->renderer('en'), params: ['name' => '%foo%', 'count' => 3, 'reason' => 'disk_space']);

        $this->assertSame('Test event: %foo%', $rendered->title);
        $this->assertFalse($rendered->degraded);
    }

    public function testDefaultFillsAParameterMissingFromAnOlderRecord(): void
    {
        $rendered = $this->render($this->renderer('en'), params: ['name' => 'Naruto', 'reason' => 'network']);

        $this->assertSame('0 items, reason: network error', $rendered->body);
        $this->assertFalse($rendered->degraded);
    }

    public function testUnknownKindRendersTheFallbackTextWithAWarning(): void
    {
        $logger = NotificationTestKit::logger();

        $rendered = $this->render($this->renderer('ru', logger: $logger), kind: 'removed_kind');

        $this->assertSame('Оповещение', $rendered->title);
        $this->assertSame('Не удалось показать текст оповещения от 2026-10-09 12:00.', $rendered->body);
        $this->assertTrue($rendered->degraded);
        $this->assertTrue($logger->has('warning'));
    }

    public function testDegradedUserLocaleIsRerenderedInTheNearestBuiltInLocale(): void
    {
        $messages = NotificationTestKit::messages();
        // a translation plugin's locale with a placeholder that does not match the parameters
        $messages['kk'] = ['notification.test_event.title' => 'Оқиға: %title%'] + $messages['ru'];

        $rendered = $this->render($this->renderer('kk', $messages));

        $this->assertSame('Тестовое событие: Naruto', $rendered->title);
        $this->assertFalse($rendered->degraded);
    }

    public function testEveryLocaleDegradedGivesTheFallbackTextAndAWarning(): void
    {
        $messages = NotificationTestKit::messages();
        $messages['ru']['notification.test_event.body'] = '%missing% items';
        $messages['en']['notification.test_event.body'] = '%missing% items';
        $logger = NotificationTestKit::logger();

        $rendered = $this->render($this->renderer('ru', $messages, $logger));

        $this->assertSame('Оповещение', $rendered->title);
        $this->assertTrue($rendered->degraded);
        $this->assertTrue($logger->has('warning'));
    }

    public function testTextEqualToItsKeyOrEmptyIsDegraded(): void
    {
        $messages = NotificationTestKit::messages();
        unset($messages['ru']['notification.test_event.title']);
        $messages['en']['notification.test_event.title'] = '';

        $rendered = $this->render($this->renderer('ru', $messages));

        $this->assertTrue($rendered->degraded);
        $this->assertSame('Оповещение', $rendered->title);
    }

    public function testUnknownCodeIsDegraded(): void
    {
        $rendered = $this->render($this->renderer('en'), params: ['name' => 'A', 'count' => 1, 'reason' => 'gone']);

        $this->assertTrue($rendered->degraded);
    }

    public function testRenderNeverThrows(): void
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('trans')->willThrowException(new \RuntimeException('boom'));
        $settings = $this->createStub(AppSettingsProvider::class);
        $logger = NotificationTestKit::logger();
        $renderer = new NotificationRenderer(
            new NotificationKindRegistry([new NotificationTestKit()]),
            $translator,
            $settings,
            new NearestBuiltInLocale(),
            $this->createStub(NotificationSubjectLinker::class),
            $logger,
        );

        $rendered = $this->render($renderer);

        $this->assertTrue($rendered->degraded);
        $this->assertTrue($logger->has('error'));
    }

    public function testSubjectLinkIsTakenFromTheLinkerAndAFailingLinkerGivesNoLink(): void
    {
        $linker = $this->createStub(NotificationSubjectLinker::class);
        $linker->method('urlFor')->willReturn('/anime/7');
        $this->assertSame('/anime/7', $this->render($this->renderer('en', linker: $linker), type: 'anime', id: '7')->url);

        $failing = $this->createStub(NotificationSubjectLinker::class);
        $failing->method('urlFor')->willThrowException(new \RuntimeException('db'));
        $rendered = $this->render($this->renderer('en', linker: $failing), type: 'anime', id: '7');
        $this->assertNull($rendered->url);
        $this->assertSame('Test event: Naruto', $rendered->title);
    }
}
