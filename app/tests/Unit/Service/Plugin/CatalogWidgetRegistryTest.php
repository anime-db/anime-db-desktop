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

use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\CatalogWidgetRegistry;
use App\Service\Plugin\Exception\WidgetHardLimitExceededException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Tests\Fixtures\Plugin\Widget\FakeCatalogWidget;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CatalogWidgetRegistryTest extends TestCase
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
            'animedb-shikimori' => ['features' => ['new_releases' => true]],
        ]));

        $newReleases = $this->createStub(CatalogWidgetInterface::class);

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => $newReleases],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertSame($newReleases, $registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }

    public function testFindReturnsNullWhenNoWidgetIsRegisteredUnderThatKey(): void
    {
        $registry = new CatalogWidgetRegistry([], new PluginsConfigStore($this->path), $this->noopTranslator());

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }

    public function testFindReturnsNullWhenTheWidgetIsDisabled(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['new_releases' => false]],
        ]));

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => $this->createStub(CatalogWidgetInterface::class)],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }

    public function testFindAllActiveListsOnlyEnabledWidgets(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['new_releases' => false]],
            'animedb-anilist' => ['features' => ['trending' => true]],
        ]));

        $registry = new CatalogWidgetRegistry(
            [
                'animedb-shikimori:new_releases' => $this->createStub(CatalogWidgetInterface::class),
                'animedb-anilist:trending' => new FakeCatalogWidget(),
            ],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertSame(
            [[
                'pluginId' => 'animedb-anilist',
                'widgetName' => 'trending',
                'title' => 'trending',
                'pluginName' => 'animedb-anilist',
            ]],
            $registry->findAllActive(),
        );
    }

    /**
     * Issue #728: the host's slot header needs the widget's display title and the plugin's
     * manifest name, both already resolved (not a raw translation key, not a raw plugin id) —
     * reusing the same {@see CatalogWidgetRegistry::listAll()} translation logic, not a second
     * implementation.
     */
    public function testFindAllActiveResolvesTitleAndPluginNameForEachActiveWidget(): void
    {
        $this->writeManifest('animedb-shikimori', 'Shikimori');
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['new_releases' => true]],
        ]));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['widget.fake_catalog_widget.title', [], 'animedb-shikimori', null, 'New releases'],
        ]);

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => new FakeCatalogWidget()],
            new PluginsConfigStore($this->path),
            $translator,
            $this->installedPlugins(),
        );

        $this->assertSame(
            [[
                'pluginId' => 'animedb-shikimori',
                'widgetName' => 'new_releases',
                'title' => 'New releases',
                'pluginName' => 'Shikimori',
            ]],
            $registry->findAllActive(),
        );
    }

    /**
     * Same fallback rule as {@see CatalogWidgetRegistryTest::testListAllIncludesBothActiveAndInactiveWidgets()}:
     * an untranslated `titleKey` must never reach the slot header as a raw dotted key.
     */
    public function testFindAllActiveFallsBackToWidgetNameWhenTitleKeyIsUntranslated(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['new_releases' => true]],
        ]));

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => new FakeCatalogWidget()],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertSame('new_releases', $registry->findAllActive()[0]['title']);
    }

    /** Falls back to the raw plugin id when no {@see InstalledPluginsRegistry} was given to the constructor. */
    public function testFindAllActiveFallsBackToThePluginIdWhenInstalledPluginsRegistryIsNotWired(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['new_releases' => true]],
        ]));

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => new FakeCatalogWidget()],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertSame('animedb-shikimori', $registry->findAllActive()[0]['pluginName']);
    }

    public function testListAllIncludesBothActiveAndInactiveWidgets(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['new_releases' => false]],
        ]));

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => new FakeCatalogWidget()],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertSame(
            [[
                'pluginId' => 'animedb-shikimori',
                'widgetName' => 'new_releases',
                'active' => false,
                'title' => 'new_releases',
                'description' => 'new_releases',
            ]],
            $registry->listAll(),
        );
    }

    public function testListAllResolvesTitleAndDescriptionThroughTheTranslatorInThePluginsDomain(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['widget.fake_catalog_widget.title', [], 'animedb-shikimori', null, 'New releases'],
            ['widget.fake_catalog_widget.description', [], 'animedb-shikimori', null, 'Shows recently released anime.'],
        ]);

        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => new FakeCatalogWidget()],
            new PluginsConfigStore($this->path),
            $translator,
        );

        $this->assertSame(
            [[
                'pluginId' => 'animedb-shikimori',
                'widgetName' => 'new_releases',
                'active' => false,
                'title' => 'New releases',
                'description' => 'Shows recently released anime.',
            ]],
            $registry->listAll(),
        );
    }

    /**
     * Issue #742: the catalog placement's hard limit already equals what the soft recommendation
     * used to be (2), so a recommendation here could never be exceeded — the constant is not
     * declared at all rather than kept as dead configuration; see
     * {@see EntryWidgetRegistryTest::testRecommendedLimitIsTwo()} for the placement that has one.
     */
    public function testRecommendedLimitConstantDoesNotExist(): void
    {
        $this->assertFalse((new \ReflectionClass(CatalogWidgetRegistry::class))->hasConstant('RECOMMENDED_LIMIT'));
    }

    public function testSetActiveTurnsAWidgetOnAndOff(): void
    {
        $registry = new CatalogWidgetRegistry(
            ['animedb-shikimori:new_releases' => new FakeCatalogWidget()],
            new PluginsConfigStore($this->path),
            $this->noopTranslator(),
        );

        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));

        $registry->setActive(new PluginId('animedb-shikimori'), 'new_releases', true);
        $this->assertNotNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));

        $registry->setActive(new PluginId('animedb-shikimori'), 'new_releases', false);
        $this->assertNull($registry->find(new PluginId('animedb-shikimori'), 'new_releases'));
    }

    /**
     * Issue #728: the catalog placement's cap dropped from 5 to 2 — {@see CatalogWidgetRegistry::HARD_LIMIT}
     * — because a widget row now sits above the toolbar at full main-column width instead of
     * inside a height-capped grid cell. This pins the boundary itself: a third widget is rejected
     * with the very same exception a sixth used to trigger before this issue (see
     * {@see EntryWidgetRegistryTest::testSetActiveThrowsWhenEnablingAWidgetWouldExceedTheHardLimit()}
     * for the placement that keeps the old limit of 5).
     */
    public function testSetActiveThrowsWhenEnablingAThirdCatalogWidgetWouldExceedTheHardLimit(): void
    {
        $this->assertSame(2, CatalogWidgetRegistry::HARD_LIMIT);

        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['w1' => true, 'w2' => true]],
        ]));

        $widgets = [];
        foreach (['w1', 'w2', 'w3'] as $name) {
            $widgets["animedb-shikimori:{$name}"] = new FakeCatalogWidget();
        }

        $registry = new CatalogWidgetRegistry($widgets, new PluginsConfigStore($this->path), $this->noopTranslator());

        $this->assertSame(2, \count($registry->findAllActive()));

        try {
            $registry->setActive(new PluginId('animedb-shikimori'), 'w3', true);
            $this->fail(WidgetHardLimitExceededException::class.' was not thrown.');
        } catch (WidgetHardLimitExceededException $exception) {
            $this->assertSame(2, $exception->limit);
        }
    }

    public function testSetActiveAllowsDisablingAWidgetEvenAtTheHardLimit(): void
    {
        file_put_contents($this->path, json_encode([
            'animedb-shikimori' => ['features' => ['w1' => true, 'w2' => true]],
        ]));

        $widgets = [];
        foreach (['w1', 'w2'] as $name) {
            $widgets["animedb-shikimori:{$name}"] = new FakeCatalogWidget();
        }

        $registry = new CatalogWidgetRegistry($widgets, new PluginsConfigStore($this->path), $this->noopTranslator());

        $registry->setActive(new PluginId('animedb-shikimori'), 'w1', false);

        $this->assertSame(1, \count($registry->findAllActive()));
    }
}
