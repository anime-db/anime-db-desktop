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

use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\CatalogReader;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginNamespace;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives every plugin service that type-hints {@see CatalogReaderInterface} in its constructor a
 * {@see CatalogReader} instance bound to *that plugin's own* id (issue #577) — the same reasoning
 * and mechanics as {@see PluginDataStoreScopePass}/{@see SettingsStoreScopePass}: the plugin never
 * names its own id in the constructor, so the host has to supply the right instance itself, keyed
 * off which plugin the consuming class belongs to.
 *
 * Same class-to-plugin matching as {@see TagPluginServicesPass} (own service tags, not constructor
 * bindings) — a service belongs to a plugin when its class lives under that plugin's namespace
 * (`AnimeDb\Plugins\<StudlyId>\`), the only way to recover a plugin's id from a bare service
 * definition. Only adds the binding to definitions whose constructor actually declares a
 * `CatalogReaderInterface` parameter: Symfony's `ResolveBindingsPass` hard-fails compilation on a
 * binding with no matching argument anywhere on that definition ("may be unused... should be
 * removed"), and most plugin services have no reason to want one.
 *
 * Must run after {@see TagPluginServicesPass}: the per-plugin {@see CatalogReader} it constructs
 * below is wired with that plugin's own `app.filler`/`app.sync`/`app.search_by_plugin`-tagged
 * service, if any, as its lazy external-id resolver, and those tags only exist once
 * `TagPluginServicesPass` has run.
 *
 * The resolver is always passed as a {@see ServiceClosureArgument}, never a bare {@see Reference}:
 * a plugin's filler/syncer/search-by-plugin service can itself type-hint `CatalogReaderInterface`
 * (it naturally wants to read the record it is about to fill), which would otherwise make the
 * `CatalogReader` built below and that same service depend on each other — a service graph cycle
 * closed on itself (`ServiceCircularReferenceException` at compile time). A one-level check on the
 * resolver's own constructor (skip it if it directly type-hints `CatalogReaderInterface`) only
 * catches the cycle when the resolver is the consumer itself; it still misses a cycle formed
 * through any intermediate collaborator the resolver depends on, and that dependency graph is
 * unbounded in depth. `ServiceClosureArgument` sidesteps the whole class of cycle structurally:
 * Symfony's `CheckCircularReferencesPass` treats an argument wrapped this way as a lazy edge and
 * never treats it as part of a construction-time cycle, because the wrapped service is not actually
 * instantiated until {@see CatalogReader} calls the closure inside `read()` — see
 * {@see CatalogReader::resolveExternalId()}.
 */
final class CatalogReaderScopePass implements CompilerPassInterface
{
    /** @var list<string> tags whose service is eligible as a plugin's external-id resolver */
    private const RESOLVER_TAGS = ['app.filler', 'app.sync', 'app.search_by_plugin'];

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

        $resolverServiceIdsByPlugin = $this->resolverServiceIdsByPlugin($container);

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

            if (!class_exists($class) || !$this->wantsCatalogReader($class)) {
                continue;
            }

            $definition->setBindings([
                ...$definition->getBindings(),
                CatalogReaderInterface::class => new Reference($this->catalogReaderServiceId(
                    $container,
                    $pluginId,
                    $resolverServiceIdsByPlugin[$pluginId] ?? null,
                )),
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

    /** @return array<string, string> plugin id => id of one of its own app.filler/app.sync/app.search_by_plugin services */
    private function resolverServiceIdsByPlugin(ContainerBuilder $container): array
    {
        $resolvers = [];
        foreach (self::RESOLVER_TAGS as $tag) {
            foreach ($container->findTaggedServiceIds($tag) as $serviceId => $tagAttributes) {
                foreach ($tagAttributes as $attributes) {
                    $pluginId = $attributes['id'] ?? null;
                    if (!\is_string($pluginId) || isset($resolvers[$pluginId])) {
                        continue;
                    }

                    $resolvers[$pluginId] = $serviceId;
                }
            }
        }

        return $resolvers;
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
    private function wantsCatalogReader(string $class): bool
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return false;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === CatalogReaderInterface::class) {
                return true;
            }
        }

        return false;
    }

    private function catalogReaderServiceId(ContainerBuilder $container, string $pluginId, ?string $resolverServiceId): string
    {
        $serviceId = 'app.catalog_reader.'.$pluginId;

        if (!$container->hasDefinition($serviceId)) {
            // A raw `new PluginId($pluginId)` argument here would make PhpDumper fail to compile
            // the container ("Unable to dump a service container if a parameter is an object") the
            // moment any plugin actually consumes this service — PhpDumper only knows how to
            // inline a Reference/Definition/scalar as a constructor argument, not an arbitrary
            // object instance. Wrapping it as its own inline Definition keeps it dumpable.
            $container->setDefinition($serviceId, (new Definition(CatalogReader::class))
                ->setArguments([
                    (new Definition(PluginId::class))->setArguments([$pluginId]),
                    new Reference('doctrine'),
                    $resolverServiceId !== null ? new ServiceClosureArgument(new Reference($resolverServiceId)) : null,
                    new Reference(LoggerInterface::class),
                ])
                ->setPublic(false));
        }

        return $serviceId;
    }
}
