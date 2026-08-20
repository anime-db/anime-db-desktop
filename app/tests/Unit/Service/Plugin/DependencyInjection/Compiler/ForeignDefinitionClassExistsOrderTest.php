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

namespace App\Tests\Unit\Service\Plugin\DependencyInjection\Compiler;

use App\Service\Plugin\DependencyInjection\Compiler\OwnManifestScopePass;
use App\Service\Plugin\DependencyInjection\Compiler\PluginDataStoreScopePass;
use App\Service\Plugin\DependencyInjection\Compiler\SettingsStoreScopePass;
use App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Regression test for issue #458: every plugin-scope compiler pass must filter a definition by
 * plugin namespace ({@see InstalledPluginsRegistry}) *before* calling `class_exists()` on it, not
 * after. `class_exists()` is not a safe probe — the container carries definitions registered by
 * third-party bundles (`doctrine.orm.validator.unique` from doctrine-bundle is the real-world
 * trigger) whose class extends a parent from a package this app never requires, and PHP fatals
 * while autoloading such a class rather than returning `false`. The fixture below reproduces that
 * shape without depending on `doctrine.orm.validator.unique` itself, so this test keeps failing the
 * inverted-order case even if `symfony/validator` is ever added as a dependency.
 *
 * Only asserts on the pass that inverted the order first: {@see TagPluginServicesPass} was already
 * fixed by issue #287 and is included here so that fix stays covered by the same regression.
 */
final class ForeignDefinitionClassExistsOrderTest extends TestCase
{
    private const string FOREIGN_CLASS = 'Foreign\ClassExistsOrderFixture\ServiceWithMissingParent';

    private string $pluginsDir;

    private string $fixtureFile;

    /** @var callable */
    private $autoloader;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir().'/anime-class-exists-order-test-'.uniqid();
        mkdir($this->pluginsDir, recursive: true);

        // Lazily declares a class outside any plugin namespace whose parent can never be resolved
        // — mirrors doctrine-bundle's unconditional `doctrine.orm.validator.unique` definition,
        // whose class extends a `symfony/validator` class this app does not require. Declared only
        // on demand (via spl_autoload_register, exactly how `class_exists()` triggers it in
        // production) so an inverted-order pass fatals here the same way it does in a real cold
        // compile, while a correctly-ordered pass never touches it at all.
        $this->fixtureFile = $this->pluginsDir.'/ServiceWithMissingParent.php';
        file_put_contents($this->fixtureFile, "<?php\n"
            ."declare(strict_types=1);\n"
            ."namespace Foreign\ClassExistsOrderFixture;\n"
            .'final class ServiceWithMissingParent extends \NonInstalled\Package\MissingParentClass {}'."\n");

        $this->autoloader = function (string $class): void {
            if ($class === self::FOREIGN_CLASS) {
                require $this->fixtureFile;
            }
        };
        spl_autoload_register($this->autoloader);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoloader);
        $this->removeDirectory($this->pluginsDir);
    }

    public function testPluginDataStoreScopePassSkipsForeignDefinitionBeforeReflectingIt(): void
    {
        $this->assertPassSkipsForeignDefinition(new PluginDataStoreScopePass($this->registry()));
    }

    public function testOwnManifestScopePassSkipsForeignDefinitionBeforeReflectingIt(): void
    {
        $this->assertPassSkipsForeignDefinition(new OwnManifestScopePass($this->registry()));
    }

    public function testSettingsStoreScopePassSkipsForeignDefinitionBeforeReflectingIt(): void
    {
        $this->assertPassSkipsForeignDefinition(new SettingsStoreScopePass($this->registry()));
    }

    public function testTagPluginServicesPassSkipsForeignDefinitionBeforeReflectingIt(): void
    {
        $this->assertPassSkipsForeignDefinition(new TagPluginServicesPass($this->registry(), new NullLogger()));
    }

    private function assertPassSkipsForeignDefinition(CompilerPassInterface $pass): void
    {
        $container = new ContainerBuilder();
        $container->register(self::FOREIGN_CLASS, self::FOREIGN_CLASS);

        // No exception/fatal means the pass matched the plugin namespace (and found no match)
        // before ever calling class_exists() on the foreign class.
        $pass->process($container);

        $this->assertFalse(
            class_exists(self::FOREIGN_CLASS, false),
            'The foreign definition must never be autoloaded — matchPluginId() must run before class_exists().',
        );
    }

    private function registry(): InstalledPluginsRegistry
    {
        $this->writeManifest('fake-vendor');

        $registry = new InstalledPluginsRegistry(
            $this->pluginsDir,
            new PluginsConfigStore($this->pluginsDir.'/plugins.json'),
            new NullLogger(),
        );
        $registry->reconcile();

        return $registry;
    }

    private function writeManifest(string $pluginId): void
    {
        $dir = $this->pluginsDir.'/'.$pluginId;
        mkdir($dir, recursive: true);
        file_put_contents($dir.'/manifest.json', (string) json_encode([
            'id' => $pluginId,
            'name' => ucfirst($pluginId),
            'version' => '1.0.0',
            'type' => 'translation',
            'locales' => ['fr'],
            'require' => ['core' => '>=2.0.0', 'php' => '>=8.2'],
        ]));
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
}
