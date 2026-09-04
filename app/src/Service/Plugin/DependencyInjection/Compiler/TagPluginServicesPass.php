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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Search\SearchByPluginInterface;
use AnimeDb\PluginContracts\Settings\SettingsPageInterface;
use AnimeDb\PluginContracts\Sync\SyncInterface;
use AnimeDb\PluginContracts\Widget\CatalogWidgetInterface;
use AnimeDb\PluginContracts\Widget\EntryWidgetInterface;
use App\Service\Plugin\Exception\DuplicateWidgetNameException;
use App\Service\Plugin\Exception\MultipleSettingsPagesException;
use App\Service\Plugin\Exception\ReservedWidgetNameException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginNamespace;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tags plugin services with the host's internal `app.filler`/`app.search_by_plugin`/`app.sync`/
 * `app.entry_widget`/`app.catalog_widget`/`app.settings_page` tags, each carrying an `id`
 * attribute equal to the owning plugin's id, so {@see \App\Service\Plugin\FillerRegistry},
 * {@see \App\Service\Plugin\SyncRegistry}, {@see \App\Service\Storage\Search\SearchByPluginChain},
 * the widget registries and {@see \App\Service\Plugin\SettingsPageRegistry} — all consuming their
 * tag via `#[AutowireIterator(..., indexAttribute: 'id')]` — pick plugin services up (issue #278).
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
 *
 * `SettingsPageInterface` (issue #317) is the one contract in the table with an "exactly one per
 * plugin" invariant stated in its own PHPDoc. `#[AutowireIterator(indexAttribute: 'id')]` would
 * otherwise resolve a second service under the same plugin id by silently keeping only one of
 * them — whichever ends up last in compilation order — so this pass instead rejects that case
 * outright with {@see MultipleSettingsPagesException}, surfacing a plugin author's mistake at
 * container-compile time rather than as an unexplained "wrong page renders" bug later.
 *
 * `EntryWidgetInterface`/`CatalogWidgetInterface` (issue #364) get a compound `id` instead of the
 * plain plugin id every other row uses: a plugin may declare several widgets per placement, and
 * {@see \App\Service\Plugin\EntryWidgetRegistry}/{@see \App\Service\Plugin\CatalogWidgetRegistry}
 * key their `#[AutowireIterator(indexAttribute: 'id')]` on "{pluginId}:{widgetName}", `widgetName`
 * coming from the widget's own static `metadata()` (contract, not this app). A plugin's
 * `metadata()` is untrusted third-party code executed at container-compile time, so a
 * throwing/broken implementation is caught and the widget is skipped with a log entry rather than
 * failing the whole build — but a `metadata()->name` that collides with another widget of the
 * same plugin, or with a reserved `features` key (`filler`/`sync` — see
 * {@see \App\Service\Plugin\WidgetActiveTrait}), is a plugin author's mistake serious enough to
 * reject outright, same stance as `MultipleSettingsPagesException` above.
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
        SettingsPageInterface::class => 'app.settings_page',
    ];

    /** @var list<string> features keys already used to gate the plugin's own Filler/Sync capabilities */
    private const RESERVED_WIDGET_NAMES = ['filler', 'sync'];

    public function __construct(
        private readonly InstalledPluginsRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $namespacePrefixes = $this->pluginNamespacePrefixes();
        if ($namespacePrefixes === []) {
            return;
        }

        /** @var array<string, string> $settingsPageOwners plugin id => service id already tagged app.settings_page */
        $settingsPageOwners = [];

        /** @var array<string, array<string, string>> $widgetNamesByPlugin plugin id => widget name => owning service id, shared by entry and catalog widgets */
        $widgetNamesByPlugin = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            if ($definition->isAbstract()) {
                // Symfony's `_instanceof` autoconfiguration (e.g. for classes implementing
                // EventSubscriberInterface) splits an abstract `.abstract.instanceof.<Class>`
                // definition off any matching service; it has no arguments of its own, so
                // tagging it here would register the plugin's class a second time under this
                // pass's per-plugin bookkeeping (e.g. as a duplicate widget name) alongside the
                // real service.
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

            if (!class_exists($class)) {
                continue;
            }

            foreach (self::CONTRACT_TAGS as $interface => $tag) {
                if (!is_a($class, $interface, true)) {
                    continue;
                }

                if ($interface === SettingsPageInterface::class) {
                    $existingServiceId = $settingsPageOwners[$pluginId] ?? null;
                    if ($existingServiceId !== null) {
                        throw new MultipleSettingsPagesException($pluginId, $existingServiceId, $serviceId);
                    }

                    $settingsPageOwners[$pluginId] = $serviceId;
                }

                if ($interface === EntryWidgetInterface::class || $interface === CatalogWidgetInterface::class) {
                    $name = $this->widgetName($class, $pluginId, $serviceId);
                    if ($name === null) {
                        continue;
                    }

                    if (\in_array($name, self::RESERVED_WIDGET_NAMES, true)) {
                        throw new ReservedWidgetNameException($pluginId, $serviceId, $name);
                    }

                    $existingServiceId = $widgetNamesByPlugin[$pluginId][$name] ?? null;
                    if ($existingServiceId !== null) {
                        throw new DuplicateWidgetNameException($pluginId, $name, $existingServiceId, $serviceId);
                    }

                    $widgetNamesByPlugin[$pluginId][$name] = $serviceId;

                    $definition->addTag($tag, ['id' => $pluginId.':'.$name]);

                    continue;
                }

                $definition->addTag($tag, ['id' => $pluginId]);
            }
        }
    }

    /**
     * Reads a widget service's `metadata()->name`, or `null` if `metadata()` is broken (logged,
     * not thrown — a single misbehaving plugin must not take down the whole container build). A
     * plugin's `metadata()` is untrusted third-party code executed at container-compile time.
     */
    private function widgetName(string $class, string $pluginId, string $serviceId): ?string
    {
        try {
            return $class::metadata()->name;
        } catch (\Throwable $exception) {
            $this->logger->error('Skipping widget service with a broken metadata().', [
                'pluginId' => $pluginId,
                'serviceId' => $serviceId,
                'class' => $class,
                'exception' => $exception,
            ]);

            return null;
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
