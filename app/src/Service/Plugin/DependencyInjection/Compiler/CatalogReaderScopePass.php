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
 * `TagPluginServicesPass` has run. A candidate resolver is skipped, and the plugin is left with no
 * resolver, whenever its own class also type-hints `CatalogReaderInterface`
 * ({@see wantsCatalogReader()}) — the general rule behind the widget case that motivated this
 * (issue #577 — Shikimori's RelatedWidget/SimilarWidget consuming `CatalogReaderInterface`, never
 * tagged as a resolver by `TagPluginServicesPass` in the first place): whatever service the
 * `CatalogReader` we are about to build gets injected into cannot also be the service that
 * `CatalogReader` itself depends on, or the container closes a service graph cycle on itself
 * (`ServiceCircularReferenceException` at compile time, not merely a binding oddity). A filler or
 * syncer naturally wanting to read the record it is about to fill hits the exact same case.
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

                    // A resolver that itself type-hints CatalogReaderInterface would make the
                    // CatalogReader built below depend on the very service it gets injected into —
                    // a circular reference. Leave the plugin without a resolver instead.
                    $class = $container->getDefinition($serviceId)->getClass();
                    if (\is_string($class) && class_exists($class) && $this->wantsCatalogReader($class)) {
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
                    $resolverServiceId !== null ? new Reference($resolverServiceId) : null,
                    new Reference(LoggerInterface::class),
                ])
                ->setPublic(false));
        }

        return $serviceId;
    }
}
