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

namespace App\Tests\Unit\Service\Plugin;

use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\EntryWidgetRegistry;
use App\Service\Plugin\Exception\WidgetHardLimitExceededException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Fixtures\Plugin\Widget\FakeEntryWidget;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EntryWidgetRegistryTest extends TestCase
{
    private string $path;
    private string $pluginsDir;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/anime-widgets-test-'.uniqid().'.json';
        $this->pluginsDir = sys_get_temp_dir().'/anime-widgets-test-plugins-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path.'.tmp', $this->path.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->removeDirectory($this->pluginsDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function writeManifest(string $pluginId, string $name): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => $name,
            'version' => '1.0.0',
            'type' => 'integration',
            'features' => ['filler' => true],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
    }

    private function installedPlugins(): InstalledPluginsRegistry
    {
        $registry = new InstalledPluginsRegistry($this->pluginsDir, new PluginsConfigStore($this->path), new NullLogger());
        $registry->reconcile();

        return $registry;
    }

    /**
     * Echoes the translation key back unchanged, i.e. simulates a plugin that hasn't shipped
     * this key's translation yet — the registry falls back to `widgetName` in that case.
     */
    private function noopTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    public function testFindReturnsTheMatchingWidgetForACompoundPluginAndWidgetNameKey(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['related' => true, 'recommended' => true]],
        ]));

        $related = $this->createStub(EntryWidgetInterface::class);
        $recommended = $this->createStub(EntryWidgetInterface::class);

        $registry = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $related, 'animedb-shikimori:recommended' => $recommended],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertSame($related, $registry->find(new PluginId('animedb-shikimori'), 'related'));
        $this->assertSame($recommended, $registry->find(new PluginId('animedb-shikimori'), 'recommended'));
    }

    public function testFindReturnsNullWhenNoWidgetIsRegisteredUnderThatKey(): void
    {
        $registry = new EntryWidgetRegistry([], new PluginsConfigStore($this->path), $this->noopTranslator());

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'related'));
    }

    public function testFindReturnsNullWhenTheWidgetIsDisabledIndependentlyOfOtherWidgets(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['related' => false, 'recommended' => true]],
        ]));

        $related = $this->createStub(EntryWidgetInterface::class);
        $recommended = $this->createStub(EntryWidgetInterface::class);

        $registry = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => $related, 'animedb-shikimori:recommended' => $recommended],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'related'));
        $this->assertSame($recommended, $registry->find(new PluginId('animedb-shikimori'), 'recommended'));
    }

    public function testFindAllActiveListsOnlyEnabledWidgets(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['related' => false, 'recommended' => true]],
            'animedb-anilist' => ['features' => ['related' => true]],
        ]));

        $registry = new EntryWidgetRegistry(
            [
                'animedb-shikimori:related' => $this->createStub(EntryWidgetInterface::class),
                'animedb-shikimori:recommended' => new FakeEntryWidget(),
                'animedb-anilist:related' => new FakeEntryWidget(),
            ],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertSame(
            [
                [
                    'pluginId' => 'animedb-shikimori',
                    'widgetName' => 'recommended',
                    'title' => 'recommended',
                    'pluginName' => 'animedb-shikimori',
                ],
                [
                    'pluginId' => 'animedb-anilist',
                    'widgetName' => 'related',
                    'title' => 'related',
                    'pluginName' => 'animedb-anilist',
                ],
            ],
            $registry->findAllActive(),
        );
    }

    /**
     * Issue #728: the host's slot header needs the widget's display title and the plugin's
     * manifest name, both already resolved — reusing the same {@see EntryWidgetRegistry::listAll()}
     * translation logic, not a second implementation.
     */
    public function testFindAllActiveResolvesTitleAndPluginNameForEachActiveWidget(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['related' => true]],
        ]));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['widget.fake_entry_widget.title', [], 'animedb-shikimori', null, 'Related titles'],
        ]);

        $registry = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => new FakeEntryWidget()],
            new PluginsConfigStore($this->path),
            $translator,
            $this->installedPlugins(),
        );

        $this->assertSame(
            [[
                'pluginId' => 'animedb-shikimori',
                'widgetName' => 'related',
                'title' => 'Related titles',
                'pluginName' => 'Shikimori',
            ]],
            $registry->findAllActive(),
        );
    }

    /**
     * Same fallback rule as {@see EntryWidgetRegistryTest::testListAllIncludesBothActiveAndInactiveWidgets()}:
     * an untranslated `titleKey` must never reach the slot header as a raw dotted key.
     */
    public function testFindAllActiveFallsBackToWidgetNameWhenTitleKeyIsUntranslated(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['related' => true]],
        ]));

        $registry = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => new FakeEntryWidget()],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertSame('related', $registry->findAllActive()[0]['title']);
    }

    public function testListAllIncludesBothActiveAndInactiveWidgets(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['related' => false, 'recommended' => true]],
        ]));

        $registry = new EntryWidgetRegistry(
            [
                'animedb-shikimori:related' => new FakeEntryWidget(),
                'animedb-shikimori:recommended' => new FakeEntryWidget(),
            ],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertSame(
            [
                [
                    'pluginId' => 'animedb-shikimori',
                    'widgetName' => 'related',
                    'active' => false,
                    'title' => 'related',
                    'description' => 'related',
                ],
                [
                    'pluginId' => 'animedb-shikimori',
                    'widgetName' => 'recommended',
                    'active' => true,
                    'title' => 'recommended',
                    'description' => 'recommended',
                ],
            ],
            $registry->listAll(),
        );
    }

    public function testListAllResolvesTitleAndDescriptionThroughTheTranslatorInThePluginsDomain(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['widget.fake_entry_widget.title', [], 'animedb-shikimori', null, 'Related titles'],
            ['widget.fake_entry_widget.description', [], 'animedb-shikimori', null, 'Shows related anime titles.'],
        ]);

        $registry = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => new FakeEntryWidget()],
            new PluginsConfigStore($this->path),
            $translator,
        );

        $this->assertSame(
            [[
                'pluginId' => 'animedb-shikimori',
                'widgetName' => 'related',
                'active' => false,
                'title' => 'Related titles',
                'description' => 'Shows related anime titles.',
            ]],
            $registry->listAll(),
        );
    }

    public function testSetActiveTurnsAWidgetOnAndOff(): void
    {
        $registry = new EntryWidgetRegistry(
            ['animedb-shikimori:related' => new FakeEntryWidget()],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'related'));

        $registry->setActive(new PluginId('animedb-shikimori'), 'related', true);
        $this->assertNotNull($registry->find(new PluginId('animedb-shikimori'), 'related'));

        $registry->setActive(new PluginId('animedb-shikimori'), 'related', false);
        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'related'));
    }

    public function testSetActiveThrowsWhenEnablingAWidgetWouldExceedTheHardLimit(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['w1' => true, 'w2' => true, 'w3' => true, 'w4' => true, 'w5' => true]],
        ]));

        $widgets = [];
        foreach (['w1', 'w2', 'w3', 'w4', 'w5', 'w6'] as $name) {
            $widgets["animedb-shikimori:{$name}"] = new FakeEntryWidget();
        }

        $registry = new EntryWidgetRegistry($widgets, new PluginsConfigStore($this->path), $this->noopTranslator());

        $this->assertSame(5, \count($registry->findAllActive()));

        $this->expectException(WidgetHardLimitExceededException::class);
        $registry->setActive(new PluginId('animedb-shikimori'), 'w6', true);
    }

    public function testSetActiveAllowsDisablingAWidgetEvenAtTheHardLimit(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['w1' => true, 'w2' => true, 'w3' => true, 'w4' => true, 'w5' => true]],
        ]));

        $widgets = [];
        foreach (['w1', 'w2', 'w3', 'w4', 'w5'] as $name) {
            $widgets["animedb-shikimori:{$name}"] = new FakeEntryWidget();
        }

        $registry = new EntryWidgetRegistry($widgets, new PluginsConfigStore($this->path), $this->noopTranslator());

        $registry->setActive(new PluginId('animedb-shikimori'), 'w1', false);

        $this->assertSame(4, \count($registry->findAllActive()));
    }

    public function testSetActiveAllowsReenablingAnAlreadyActiveWidgetAtTheHardLimit(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['w1' => true, 'w2' => true, 'w3' => true, 'w4' => true, 'w5' => true]],
        ]));

        $widgets = [];
        foreach (['w1', 'w2', 'w3', 'w4', 'w5'] as $name) {
            $widgets["animedb-shikimori:{$name}"] = new FakeEntryWidget();
        }

        $registry = new EntryWidgetRegistry($widgets, new PluginsConfigStore($this->path), $this->noopTranslator());

        $registry->setActive(new PluginId('animedb-shikimori'), 'w1', true);

        $this->assertSame(5, \count($registry->findAllActive()));
    }
}
