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

namespace App\Service\Plugin\DependencyInjection\Compiler;

use AnimeDb\PluginContracts\CatalogWidgetInterface;
use AnimeDb\PluginContracts\EntryWidgetInterface;
use AnimeDb\PluginContracts\FillerInterface;
use AnimeDb\PluginContracts\SearchByPluginInterface;
use AnimeDb\PluginContracts\SyncInterface;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginNamespace;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tags plugin services with the host's internal `app.filler`/`app.search_by_plugin`/`app.sync`/
 * `app.entry_widget`/`app.catalog_widget` tags, each carrying an `id` attribute equal to the
 * owning plugin's id, so {@see \App\Service\Plugin\FillerRegistry},
 * {@see \App\Service\Plugin\SyncRegistry}, {@see \App\Service\Storage\Search\SearchByPluginChain}
 * and the widget registries — all consuming their tag via
 * `#[AutowireIterator(..., indexAttribute: 'id')]` — pick plugin services up (issue #278).
 *
 * `_instanceof` in `services.yaml` only tags services declared in that same file. A plugin's own
 * services are declared in its bundle's own DI config, loaded separately on boot (issue #218), so
 * `_instanceof` never sees them — a plugin would otherwise have to tag its own service by hand,
 * baking the host's internal tags/ids into itself. Running this as a compiler pass instead reaches
 * every definition in the compiled container regardless of which file declared it: a plugin only
 * has to implement a contract interface and register its class as a service.
 *
 * A service belongs to a plugin when its class lives under that plugin's namespace
 * (`AnimeDb\Plugins\<StudlyId>\`, the same derivation {@see PluginNamespace} shares with
 * {@see \App\Service\Plugin\PluginLoader}) — the plugin id itself is not otherwise recoverable
 * from a service definition.
 *
 * Interface inheritance between contracts (`FillerInterface extends SearchByPluginInterface`,
 * `SyncInterface extends FillerInterface`) means a single service can match several rows below at
 * once — each matching interface gets its own tag, not just the most specific one, since a Filler
 * plugin still needs to show up in {@see \App\Service\Storage\Search\SearchByPluginChain} and a
 * Sync plugin still needs to show up as a Filler.
 */
final class TagPluginServicesPass implements CompilerPassInterface
{
    /** @var array<class-string, string> contract interface => host tag name */
    private const CONTRACT_TAGS = [
        SyncInterface::class => 'app.sync',
        FillerInterface::class => 'app.filler',
        SearchByPluginInterface::class => 'app.search_by_plugin',
        EntryWidgetInterface::class => 'app.entry_widget',
        CatalogWidgetInterface::class => 'app.catalog_widget',
    ];

    public function __construct(
        private readonly InstalledPluginsRegistry $registry,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $namespacePrefixes = $this->pluginNamespacePrefixes();
        if ([] === $namespacePrefixes) {
            return;
        }

        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if (null === $class || !class_exists($class)) {
                continue;
            }

            $pluginId = $this->matchPluginId($class, $namespacePrefixes);
            if (null === $pluginId) {
                continue;
            }

            foreach (self::CONTRACT_TAGS as $interface => $tag) {
                if (is_a($class, $interface, true)) {
                    $definition->addTag($tag, ['id' => $pluginId]);
                }
            }
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
}
