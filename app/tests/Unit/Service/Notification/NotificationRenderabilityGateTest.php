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
use App\Tests\Support\NotificationRenderabilityGate;
use App\Tests\Support\NotificationTestKit;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Exercises the gate on the test kind (green and red cases) and runs it on the real definitions,
 * fixtures and catalogs (green on the empty set of production kinds).
 */
final class NotificationRenderabilityGateTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/notification_gate_'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    /** @param array<string, array<string, string>>|null $messages */
    private function gate(?array $messages = null, bool $withDefinition = true): NotificationRenderabilityGate
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('array', new ArrayLoader());
        foreach ($messages ?? NotificationTestKit::messages() as $locale => $catalogue) {
            $translator->addResource('array', $catalogue, $locale);
        }
        $registry = new NotificationKindRegistry($withDefinition ? [NotificationTestKit::definition()] : []);
        $renderer = new NotificationRenderer(
            $registry,
            $translator,
            $this->createStub(AppSettingsProvider::class),
            new NearestBuiltInLocale(),
            $this->createStub(NotificationSubjectLinker::class),
            NotificationTestKit::logger(),
        );

        return new NotificationRenderabilityGate($registry, $renderer, $translator, $this->dir);
    }

    /** @param array<string, mixed> $override */
    private function writeFixture(string $name, array $override = []): void
    {
        $row = [
            'kind' => 'test_event',
            'severity' => 'warning',
            'params' => ['name' => 'Sample', 'count' => 3, 'reason' => 'disk_space'],
            'subject' => ['type' => 'anime', 'id' => '7'],
        ];
        file_put_contents($this->dir.'/'.$name, json_encode(array_replace($row, $override)));
    }

    public function testGreenWhenFixtureMatchesTheFactoryAndRendersInEveryLocale(): void
    {
        $this->writeFixture('test_event.v1.json');

        $this->assertSame([], $this->gate()->violations());
    }

    public function testOlderFixtureWithoutAnAddedParameterStillRendersThroughItsDefault(): void
    {
        $this->writeFixture('test_event.v1.json', ['params' => ['name' => 'Old', 'reason' => 'network']]);
        $this->writeFixture('test_event.v2.json');

        $this->assertSame([], $this->gate()->violations());
    }

    public function testRedWhenAKindHasNoFixture(): void
    {
        $this->assertSame(['kind "test_event" has no fixture'], $this->gate()->violations());
    }

    public function testRedWhenAFixtureRefersToNoDefinition(): void
    {
        $this->writeFixture('test_event.v1.json');

        $this->assertSame(['fixture of "test_event" refers to no kind definition'], $this->gate(withDefinition: false)->violations());
    }

    public function testRedWhenTheLatestFixtureDiffersFromTheFactoryIncludingTypes(): void
    {
        $this->writeFixture('test_event.v1.json', ['params' => ['name' => 'Sample', 'count' => '3', 'reason' => 'disk_space']]);

        $violations = $this->gate()->violations();

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('differs from what the factory builds', $violations[0]);
    }

    public function testRedWhenAKeyIsDefinedOnlyThroughFallbackAndNotInTheLocalesOwnCatalogue(): void
    {
        $this->writeFixture('test_event.v1.json');
        $messages = NotificationTestKit::messages();
        unset($messages['ru']['notification.test_event.reason.network']);

        $violations = $this->gate($messages)->violations();

        $this->assertContains(
            'test_event.v1.json: key "notification.test_event.reason.network" is not defined in the "ru" catalogue itself',
            $violations,
        );
    }

    public function testRedWhenARenderDegrades(): void
    {
        $this->writeFixture('test_event.v1.json');
        $messages = NotificationTestKit::messages();
        $messages['en']['notification.test_event.body'] = '%count% items, %unknown%';

        $violations = $this->gate($messages)->violations();

        $this->assertNotSame([], $violations);
        $this->assertStringContainsString('renders degraded in "en"', $violations[0]);
    }

    public function testRealDefinitionsFixturesAndCatalogsPassTheGate(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $translator = $container->get(TranslatorInterface::class);
        $registry = $container->get(NotificationKindRegistry::class);
        $this->assertInstanceOf(Translator::class, $translator);
        $this->assertInstanceOf(NotificationKindRegistry::class, $registry);
        $renderer = new NotificationRenderer(
            $registry,
            $translator,
            $this->createStub(AppSettingsProvider::class),
            new NearestBuiltInLocale(),
            $this->createStub(NotificationSubjectLinker::class),
            NotificationTestKit::logger(),
        );

        $gate = new NotificationRenderabilityGate($registry, $renderer, $translator, \dirname(__DIR__, 3).'/Fixtures/Notifications');

        $this->assertSame([], $gate->violations());
    }

    public function testFallbackTextExistsInBothBuiltInLocalesWithTheDatePlaceholder(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $this->assertInstanceOf(Translator::class, $translator);

        foreach (['ru', 'en'] as $locale) {
            $this->assertNotSame('notification.fallback.title', $translator->trans('notification.fallback.title', [], 'messages', $locale));
            $this->assertStringContainsString('%date%', $translator->getCatalogue($locale)->get('notification.fallback.body'));
        }
    }
}
