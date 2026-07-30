<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Manifest\PluginType;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

/**
 * Drives what {@see \App\Kernel} wires up for installed plugins on every boot (issue #218),
 * branching on {@see InstalledPluginsRegistry} + {@see PluginsConfigStore}'s enabled flags
 * (#241) by `manifest->type`:
 *
 * - {@see PluginType::Integration} (Filler/Widget/Search/Sync) is autoloaded from an isolated
 *   namespace (never through the app's own `composer.json` — plugins_architecture.md §5
 *   "Автозагрузка классов"); its `plugin-routing.yaml` and `templates/` get wired up too. A
 *   bundle class (`<namespace><Studly>Bundle`) is optional: most integration plugins need
 *   nothing beyond autoload/services/routes/twig/translations, all of which are wired without
 *   a bundle instance. A plugin only needs one when it brings its own DI extension, compiler
 *   passes, or Doctrine mappings — if the class exists, it's instantiated for
 *   `registerBundles()`; if it doesn't, the plugin loads normally without one.
 * - {@see PluginType::Translation} is purely declarative — no bundle, no autoload, no
 *   routes: only its `translations/` directory is exposed, for the Symfony Translator's
 *   search paths.
 *
 * `manifest.json` intentionally carries neither a namespace nor a bundle class name
 * (`AnimeDb\PluginContracts\Manifest\Manifest` has no such field) — both are derived,
 * deterministically, from the plugin id via {@see PluginNamespace}: "vendor-name" becomes
 * namespace `AnimeDb\Plugins\VendorName`, bundle class `AnimeDb\Plugins\VendorName\VendorNameBundle`,
 * loaded from `<installPath>/src/`. Keeping the convention in one place means it can still
 * change later without touching callers — including
 * {@see DependencyInjection\Compiler\TagPluginServicesPass} (issue #278),
 * which matches a plugin service's class the same way.
 *
 * A plugin that fails to load (missing `src/`, an invalid bundle class, missing
 * `translations/`) is skipped and logged, not fatal for the rest — same policy as
 * {@see InstalledPluginsRegistry::reconcile()}. A missing bundle class is not a failure: it's
 * the expected shape for a bundle-less integration plugin, so it's only debug-logged.
 */
final class PluginLoader
{
    /**
     * @var array<string, string> namespace prefix => source directory already registered
     *                            with spl_autoload_register() in this process, to avoid piling up duplicate
     *                            autoloaders across repeated Kernel boots (e.g. in tests or a non-worker dev server)
     */
    private static array $registeredAutoloadRoots = [];

    /**
     * @var list<InstalledPlugin>|null
     */
    private ?array $integrationPluginsCache = null;

    public function __construct(
        private readonly InstalledPluginsRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<BundleInterface>
     */
    public function integrationBundles(): array
    {
        $bundles = [];

        foreach ($this->integrationPlugins() as $plugin) {
            $bundle = $this->loadBundle($plugin);
            if ($bundle !== null) {
                $bundles[] = $bundle;
            }
        }

        return $bundles;
    }

    /**
     * Registers the spl_autoload_register() callback for every enabled integration plugin,
     * without instantiating their bundle classes.
     *
     * Must run unconditionally on every boot, before Symfony decides whether to call
     * {@see \App\Kernel::registerBundles()} at all: once the container has been compiled once,
     * `KernelTrait::initializeBundles()` skips `registerBundles()` on later boots and instead
     * `require`s a dumped `<Container>.bundles.php` that does `new \Some\Plugin\FooBundle()`
     * directly — if the autoloader for that namespace was only ever registered inside
     * {@see self::loadBundle()} (reached from `registerBundles()`), that `new` fails with a
     * class-not-found error on every boot after the first.
     */
    public function registerAutoloadForIntegrationPlugins(): void
    {
        foreach ($this->integrationPlugins() as $plugin) {
            $srcDir = $plugin->installPath.\DIRECTORY_SEPARATOR.'src';
            if (is_dir($srcDir)) {
                $this->registerAutoload($this->namespacePrefix($plugin), $srcDir);
            }
        }
    }

    /**
     * @return array<string, string> absolute `templates/` directory => Twig namespace
     *                               (without the leading "@")
     */
    public function twigPaths(): array
    {
        $paths = [];

        foreach ($this->integrationPlugins() as $plugin) {
            $templatesDir = $plugin->installPath.\DIRECTORY_SEPARATOR.'templates';
            if (is_dir($templatesDir)) {
                $paths[$templatesDir] = $this->studlyId($plugin);
            }
        }

        return $paths;
    }

    /**
     * @return list<string> absolute paths of existing `plugin-routing.yaml` files
     */
    public function routingFiles(): array
    {
        $files = [];

        foreach ($this->integrationPlugins() as $plugin) {
            $routingFile = $plugin->installPath.\DIRECTORY_SEPARATOR.'plugin-routing.yaml';
            if (is_file($routingFile)) {
                $files[] = $routingFile;
            }
        }

        return $files;
    }

    /**
     * @return array<string, string> plugin namespace prefix => absolute `src/` directory, for every
     *                               enabled integration plugin that has one — lets
     *                               {@see \App\Kernel::configureContainer()} register a plugin's classes
     *                               as autowired, autoconfigured services without the plugin needing a
     *                               DI config of its own (issue #282)
     */
    public function integrationPluginServices(): array
    {
        $paths = [];

        foreach ($this->integrationPlugins() as $plugin) {
            $srcDir = $plugin->installPath.\DIRECTORY_SEPARATOR.'src';
            if (is_dir($srcDir)) {
                $paths[$this->namespacePrefix($plugin)] = $srcDir;
            }
        }

        return $paths;
    }

    /**
     * @return list<string> absolute `translations/` directories of enabled "translation" plugins
     */
    public function translationPaths(): array
    {
        $paths = [];

        foreach ($this->registry->enabled() as $plugin) {
            if ($plugin->manifest->type !== PluginType::Translation) {
                continue;
            }

            $translationsDir = $plugin->installPath.\DIRECTORY_SEPARATOR.'translations';
            if (is_dir($translationsDir)) {
                $paths[] = $translationsDir;
            } else {
                $this->logger->error('Skipping translation plugin without a translations/ directory.', [
                    'pluginId' => (string) $plugin->id,
                    'installPath' => $plugin->installPath,
                ]);
            }
        }

        return $paths;
    }

    /**
     * @return list<InstalledPlugin>
     */
    private function integrationPlugins(): array
    {
        return $this->integrationPluginsCache ??= array_values(array_filter(
            $this->registry->enabled(),
            static fn (InstalledPlugin $plugin): bool => $plugin->manifest->type === PluginType::Integration,
        ));
    }

    private function loadBundle(InstalledPlugin $plugin): ?BundleInterface
    {
        $studly = $this->studlyId($plugin);
        $namespace = $this->namespacePrefix($plugin);
        $srcDir = $plugin->installPath.\DIRECTORY_SEPARATOR.'src';

        if (!is_dir($srcDir)) {
            $this->logger->error('Skipping integration plugin without a src/ directory.', [
                'pluginId' => (string) $plugin->id,
                'installPath' => $plugin->installPath,
            ]);

            return null;
        }

        $this->registerAutoload($namespace, $srcDir);

        $bundleClass = $namespace.$studly.'Bundle';
        if (!class_exists($bundleClass)) {
            $this->logger->debug('Integration plugin has no bundle class; loading without one.', [
                'pluginId' => (string) $plugin->id,
                'bundleClass' => $bundleClass,
            ]);

            return null;
        }

        if (!is_a($bundleClass, BundleInterface::class, true)) {
            $this->logger->error('Skipping integration plugin: bundle class does not implement BundleInterface.', [
                'pluginId' => (string) $plugin->id,
                'bundleClass' => $bundleClass,
            ]);

            return null;
        }

        try {
            /** @var BundleInterface $bundle */
            $bundle = new $bundleClass();

            return $bundle;
        } catch (\Throwable $exception) {
            $this->logger->error('Skipping integration plugin: bundle instantiation failed.', [
                'pluginId' => (string) $plugin->id,
                'bundleClass' => $bundleClass,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function registerAutoload(string $namespacePrefix, string $srcDir): void
    {
        if ((self::$registeredAutoloadRoots[$namespacePrefix] ?? null) === $srcDir) {
            return;
        }

        spl_autoload_register(static function (string $class) use ($namespacePrefix, $srcDir): void {
            if (!str_starts_with($class, $namespacePrefix)) {
                return;
            }

            $relative = substr($class, \strlen($namespacePrefix));
            $file = $srcDir.\DIRECTORY_SEPARATOR.str_replace('\\', \DIRECTORY_SEPARATOR, $relative).'.php';

            if (is_file($file)) {
                require $file;
            }
        });

        self::$registeredAutoloadRoots[$namespacePrefix] = $srcDir;
    }

    private function studlyId(InstalledPlugin $plugin): string
    {
        return PluginNamespace::studlyId($plugin->id);
    }

    private function namespacePrefix(InstalledPlugin $plugin): string
    {
        return PluginNamespace::prefix($plugin->id);
    }
}
