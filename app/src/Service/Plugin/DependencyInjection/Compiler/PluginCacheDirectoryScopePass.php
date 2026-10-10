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

namespace App\Service\Plugin\DependencyInjection\Compiler;

use AnimeDb\PluginContracts\Cache\PluginCacheDirectoryInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginCacheDirectory;
use App\Service\Plugin\PluginNamespace;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives every plugin service that type-hints {@see PluginCacheDirectoryInterface} in its
 * constructor a {@see PluginCacheDirectory} bound to *that plugin's own* id, so one plugin cannot
 * name — and read or overwrite — another plugin's directory.
 *
 * Same class-to-plugin matching and binding rules as {@see PluginDataStoreScopePass}: a service
 * belongs to a plugin when its class lives under `AnimeDb\Plugins\<StudlyId>\`, abstract
 * definitions are skipped, and the binding is only added to definitions whose constructor
 * declares the parameter (`ResolveBindingsPass` fails compilation on an unused binding).
 */
final class PluginCacheDirectoryScopePass implements CompilerPassInterface
{
    public function __construct(
        private readonly InstalledPluginsRegistry $registry,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $namespacePrefixes = $this->pluginNamespacePrefixes();
        if ($namespacePrefixes === []) {
            return;
        }

        foreach ($container->getDefinitions() as $definition) {
            if ($definition->isAbstract()) {
                // Symfony's `_instanceof` autoconfiguration (e.g. for classes extending
                // AbstractController, or carrying #[AsController]) splits an abstract
                // `.abstract.instanceof.<Class>` definition off any matching service; it has no
                // arguments of its own, so a binding placed on it makes `ResolveBindingsPass`
                // hard-fail compilation instead of just being unused.
                continue;
            }

            $class = $definition->getClass();
            if ($class === null) {
                continue;
            }

            $pluginId = $this->matchPluginId($class, $namespacePrefixes);
            if ($pluginId === null) {
                continue;
            }

            if (!class_exists($class) || !$this->wantsPluginCacheDirectory($class)) {
                continue;
            }

            $definition->setBindings([
                ...$definition->getBindings(),
                PluginCacheDirectoryInterface::class => new Reference($this->pluginCacheDirectoryServiceId($container, $pluginId)),
            ]);
        }
    }

    /** @return array<string, string> namespace prefix => plugin id */
    private function pluginNamespacePrefixes(): array
    {
        $prefixes = [];
        foreach ($this->registry->all() as $plugin) {
            $prefixes[PluginNamespace::prefix($plugin->id)] = (string) $plugin->id;
        }

        return $prefixes;
    }

    /** @param array<string, string> $namespacePrefixes namespace prefix => plugin id */
    private function matchPluginId(string $class, array $namespacePrefixes): ?string
    {
        foreach ($namespacePrefixes as $prefix => $pluginId) {
            if (str_starts_with($class, $prefix)) {
                return $pluginId;
            }
        }

        return null;
    }

    /** @param class-string $class */
    private function wantsPluginCacheDirectory(string $class): bool
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return false;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === PluginCacheDirectoryInterface::class) {
                return true;
            }
        }

        return false;
    }

    private function pluginCacheDirectoryServiceId(ContainerBuilder $container, string $pluginId): string
    {
        $serviceId = 'app.plugin_cache_directory.'.$pluginId;

        if (!$container->hasDefinition($serviceId)) {
            // A raw `new PluginId($pluginId)` argument here would make PhpDumper fail to compile
            // the container ("Unable to dump a service container if a parameter is an object") the
            // moment any plugin actually consumes this service — PhpDumper only knows how to
            // inline a Reference/Definition/scalar as a constructor argument, not an arbitrary
            // object instance. Wrapping it as its own inline Definition keeps it dumpable.
            $container->setDefinition($serviceId, (new Definition(PluginCacheDirectory::class))
                ->setArguments([
                    (new Definition(PluginId::class))->setArguments([$pluginId]),
                    new Parameter('app.plugin_cache_dir'),
                ])
                ->setPublic(false));
        }

        return $serviceId;
    }
}
