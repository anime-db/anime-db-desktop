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

namespace App;

use App\Service\Plugin\DependencyInjection\Compiler\CatalogReaderScopePass;
use App\Service\Plugin\DependencyInjection\Compiler\OwnManifestScopePass;
use App\Service\Plugin\DependencyInjection\Compiler\PluginDataStoreScopePass;
use App\Service\Plugin\DependencyInjection\Compiler\SettingsStoreScopePass;
use App\Service\Plugin\DependencyInjection\Compiler\TagPluginServicesPass;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginLoader;
use App\Service\Plugin\PluginsConfigStore;
use Composer\InstalledVersions;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        registerBundles as private getFrameworkBundles;
        configureRoutes as private getFrameworkRoutes;
        configureContainer as private getFrameworkContainerConfiguration;
    }

    private ?PluginLoader $pluginLoader = null;
    private ?InstalledPluginsRegistry $installedPluginsRegistry = null;

    public function getCacheDir(): string
    {
        if ($runtimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null) {
            return $runtimeDir.'/cache';
        }

        return parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        if ($runtimeDir = $_SERVER['APP_RUNTIME_DIR'] ?? null) {
            return $runtimeDir.'/log';
        }

        return parent::getLogDir();
    }

    /**
     * Runs before {@see KernelInterface::registerBundles()} is even consulted: once the
     * container has compiled once, `KernelTrait::initializeBundles()` skips
     * `registerBundles()` on later boots and instead `require`s a dumped
     * `<Container>.bundles.php` that instantiates plugin bundle classes directly — those
     * still need their spl_autoload_register() callback in place, or that require crashes
     * with a class-not-found error. See {@see PluginLoader::registerAutoloadForIntegrationPlugins()}.
     */
    protected function initializeBundles(): void
    {
        $this->pluginLoader()->registerAutoloadForIntegrationPlugins();
        parent::initializeBundles();
    }

    /**
     * Reads {@see InstalledPluginsRegistry} fresh on every call — issue #218: bundles active
     * on a given boot are whatever is currently enabled, not a set baked in once and reused
     * across worker restarts (see plugins_runtime_foundation.md).
     *
     * @return iterable<\Symfony\Component\HttpKernel\Bundle\BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield from $this->getFrameworkBundles();
        yield from $this->pluginLoader()->integrationBundles();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $this->getFrameworkRoutes($routes);

        foreach ($this->pluginLoader()->routingFiles() as $routingFile) {
            $routes->import($routingFile);
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $this->getFrameworkContainerConfiguration($container);

        $twigPaths = $this->pluginLoader()->twigPaths();
        if ($twigPaths !== []) {
            $container->extension('twig', ['paths' => $twigPaths]);
        }

        $translationPaths = $this->pluginLoader()->translationPaths();
        if ($translationPaths !== []) {
            $container->extension('framework', ['translator' => ['paths' => $translationPaths]]);
        }

        // Auto-registers every integration plugin's classes as services (issue #282): a plugin
        // ships only manifest.json + src/*.php, no DI config of its own. `load()` picks up
        // non-service classes (DTOs, enums, exceptions) too, but Symfony's compiler removes
        // unused private services, so that's harmless.
        foreach ($this->pluginLoader()->integrationPluginServices() as $namespacePrefix => $srcDir) {
            $container->services()
                ->defaults()->autowire()->autoconfigure()
                ->load($namespacePrefix, $srcDir);
        }
    }

    /**
     * Registers {@see TagPluginServicesPass} (issue #278) so plugin services get their
     * `app.filler`/`app.sync`/... tags at compile time, wherever their bundle declared them —
     * unlike `_instanceof` in services.yaml, which only reaches services declared in that file.
     *
     * Also registers {@see PluginDataStoreScopePass} (issue #299) so a plugin service asking for
     * `PluginDataStoreInterface` gets an instance scoped to its own plugin id,
     * {@see SettingsStoreScopePass} (issue #316) doing the same for `SettingsStoreInterface`,
     * {@see OwnManifestScopePass} (issue #323) doing the same for `OwnManifestInterface`, and
     * {@see CatalogReaderScopePass} (issue #577) doing the same for `CatalogReaderInterface`.
     * `CatalogReaderScopePass` is added after `TagPluginServicesPass` deliberately — it looks up
     * that pass's `app.filler`/`app.sync`/`app.search_by_plugin` tags to pick each plugin's
     * external-id resolver, so it must run once those tags already exist.
     */
    public function build(ContainerBuilder $container): void
    {
        // Read once here rather than left to an %env()% default like app.core_version above:
        // there is no equivalent env var the native layer could pass in for this one, since it
        // is not configuration but a fact about this app's own vendor/composer/installed.php lock
        // — the same one every InstalledVersions::getPrettyVersion() call on this process reads
        // (issue #561).
        $container->setParameter('app.plugin_contracts_version', $this->pluginContractsVersion());

        $container->addCompilerPass(new TagPluginServicesPass($this->installedPluginsRegistry(), $this->pluginLoaderLogger()));
        $container->addCompilerPass(new PluginDataStoreScopePass($this->installedPluginsRegistry()));
        $container->addCompilerPass(new SettingsStoreScopePass($this->installedPluginsRegistry()));
        $container->addCompilerPass(new OwnManifestScopePass($this->installedPluginsRegistry()));
        $container->addCompilerPass(new CatalogReaderScopePass($this->installedPluginsRegistry()));
    }

    private function pluginLoader(): PluginLoader
    {
        if ($this->pluginLoader === null) {
            $this->pluginLoader = new PluginLoader($this->installedPluginsRegistry(), $this->pluginLoaderLogger());
        }

        return $this->pluginLoader;
    }

    /**
     * Built with real {@see coreVersion()}/{@see pluginContractsVersion()} values, not their
     * container-parameter equivalents (this instance exists before the container does) — issue
     * #561's derived `InstalledPlugin::$compatible` has to be correct here too, since this is the
     * registry {@see PluginLoader::integrationBundles()} reads to decide which plugin bundles get
     * registered at all (see the "Известный край" note in issue #561: an incompatible plugin's
     * bundle never registering here is what keeps a stale compiled container from referencing it
     * after a compatibility change).
     */
    private function installedPluginsRegistry(): InstalledPluginsRegistry
    {
        if ($this->installedPluginsRegistry === null) {
            $pluginsDir = $_SERVER['PLUGINS_DIR'] ?? $this->getProjectDir().'/var/plugins';
            $pluginsConfigPath = $_SERVER['PLUGINS_CONFIG_PATH'] ?? $this->getProjectDir().'/var/plugins.json';

            $this->installedPluginsRegistry = new InstalledPluginsRegistry(
                $pluginsDir,
                new PluginsConfigStore($pluginsConfigPath),
                $this->pluginLoaderLogger(),
                coreVersion: $this->coreVersion(),
                pluginContractsVersion: $this->pluginContractsVersion(),
                safeMode: $this->isSafeMode(),
            );
        }

        return $this->installedPluginsRegistry;
    }

    /**
     * Same source and dev-environment placeholder as `app.core_version`/`app.core_version.dev_default`
     * in `config/services.yaml` (CORE_VERSION is set the same way as PLUGINS_DIR above, by
     * `native/supervisor/env.js`) — duplicated here rather than read back from the container
     * because this runs before the container exists.
     */
    private function coreVersion(): string
    {
        return $_SERVER['CORE_VERSION'] ?? '99.99.99';
    }

    /**
     * @see self::build()'s app.plugin_contracts_version parameter — same value, computed the same
     * way, for the pre-container registry instance above.
     */
    private function pluginContractsVersion(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('anime-db/plugin-contracts');
        } catch (\OutOfBoundsException) {
            return null;
        }
    }

    /**
     * SAFE_MODE=1 (native/supervisor/env.js) is set by the native layer after repeated failed
     * startups (issue #403), to recover from a plugin that crashes the kernel bootstrap before
     * the app can even show a window to disable it.
     */
    private function isSafeMode(): bool
    {
        return ($_SERVER['SAFE_MODE'] ?? null) === '1';
    }

    /**
     * A dedicated, minimal logger, not the DI-configured one: this runs before the container
     * exists (registerBundles()/configureContainer() are pre-boot hooks), so nothing wired
     * through config/packages/monolog.yaml is reachable yet.
     */
    private function pluginLoaderLogger(): LoggerInterface
    {
        return new Logger('plugin_loader', [new StreamHandler($this->getLogDir().'/plugin_loader.log', Level::Error)]);
    }
}
