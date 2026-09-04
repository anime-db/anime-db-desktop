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

use AnimeDb\PluginContracts\Settings\SettingsStoreInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginNamespace;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Plugin\SettingsStore;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives every plugin service that type-hints {@see SettingsStoreInterface} in its constructor a
 * {@see SettingsStore} instance bound to *that plugin's own* id (issue #316) — the same reasoning
 * and mechanics as {@see PluginDataStoreScopePass} for {@see \App\Service\Plugin\PluginDataStore}:
 * the plugin never names its own id in the constructor, so the host has to supply the right
 * instance itself, keyed off which plugin the consuming class belongs to.
 *
 * Same class-to-plugin matching as {@see TagPluginServicesPass} (own service tags, not
 * constructor bindings) — a service belongs to a plugin when its class lives under that plugin's
 * namespace (`AnimeDb\Plugins\<StudlyId>\`), the only way to recover a plugin's id from a bare
 * service definition. Only adds the binding to definitions whose constructor actually declares a
 * `SettingsStoreInterface` parameter: Symfony's `ResolveBindingsPass` hard-fails compilation on a
 * binding with no matching argument anywhere on that definition ("may be unused... should be
 * removed"), and most plugin services have no reason to want one.
 */
final class SettingsStoreScopePass implements CompilerPassInterface
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

            if (!class_exists($class) || !$this->wantsSettingsStore($class)) {
                continue;
            }

            $definition->setBindings([
                ...$definition->getBindings(),
                SettingsStoreInterface::class => new Reference($this->settingsStoreServiceId($container, $pluginId)),
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
    private function wantsSettingsStore(string $class): bool
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return false;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === SettingsStoreInterface::class) {
                return true;
            }
        }

        return false;
    }

    private function settingsStoreServiceId(ContainerBuilder $container, string $pluginId): string
    {
        $serviceId = 'app.settings_store.'.$pluginId;

        if (!$container->hasDefinition($serviceId)) {
            // A raw `new PluginId($pluginId)` argument here would make PhpDumper fail to compile
            // the container ("Unable to dump a service container if a parameter is an object") the
            // moment any plugin actually consumes this service — PhpDumper only knows how to
            // inline a Reference/Definition/scalar as a constructor argument, not an arbitrary
            // object instance. Wrapping it as its own inline Definition keeps it dumpable.
            $container->setDefinition($serviceId, (new Definition(SettingsStore::class))
                ->setArguments([
                    (new Definition(PluginId::class))->setArguments([$pluginId]),
                    new Reference(PluginsConfigStore::class),
                ])
                ->setPublic(false));
        }

        return $serviceId;
    }
}
