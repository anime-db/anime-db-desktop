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

use AnimeDb\PluginContracts\Background\BackgroundTaskQueueInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\BackgroundTaskQueue;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginNamespace;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Gives every plugin service that type-hints {@see BackgroundTaskQueueInterface} in its
 * constructor a {@see BackgroundTaskQueue} instance bound to *that plugin's own* id (issue #702,
 * part 2 of 3 for #684): the plugin never names its own id in the constructor, so the host has to
 * supply the right instance itself, keyed off which plugin the consuming class belongs to. Same
 * reasoning and mechanics as
 * {@see PluginDataStoreScopePass} for
 * {@see \App\Service\Plugin\PluginDataStore}.
 *
 * Same class-to-plugin matching as {@see TagPluginServicesPass} (own service tags, not
 * constructor bindings) — a service belongs to a plugin when its class lives under that plugin's
 * namespace (`AnimeDb\Plugins\<StudlyId>\`), the only way to recover a plugin's id from a bare
 * service definition. Only adds the binding to definitions whose constructor actually declares a
 * `BackgroundTaskQueueInterface` parameter: Symfony's `ResolveBindingsPass` hard-fails compilation
 * on a binding with no matching argument anywhere on that definition ("may be unused... should be
 * removed"), and most plugin services have no reason to want one.
 *
 * The type match below is deliberately exact (`$type instanceof \ReflectionNamedType &&
 * $type->getName() === BackgroundTaskQueueInterface::class`): a union type, a setter or a subtype
 * of the interface never match and get no binding, same as the other scope passes in this family.
 * When the mismatched parameter also carries no default, that is loud — `ResolveBindingsPass` (a
 * required parameter with nothing to fall back to) or a plain "no autowiring candidate" failure
 * (an optional one, autowired the normal way) hard-fail the compile. When the mismatched
 * parameter carries a default of `null` (e.g. `?BackgroundTaskQueueInterface $queue = null`
 * written with a subtype instead), Symfony has a value to fall back to and compiles without
 * complaint — the plugin silently gets `null` instead of its queue. Nothing here can detect that
 * case (there is no signal left to detect once the binding legitimately does not apply), so it is
 * only worth remembering, not fixable in this pass.
 */
final class BackgroundTaskQueueScopePass implements CompilerPassInterface
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

            if (!class_exists($class) || !$this->wantsBackgroundTaskQueue($class)) {
                continue;
            }

            $definition->setBindings([
                ...$definition->getBindings(),
                BackgroundTaskQueueInterface::class => new Reference($this->backgroundTaskQueueServiceId($container, $pluginId)),
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
    private function wantsBackgroundTaskQueue(string $class): bool
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return false;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === BackgroundTaskQueueInterface::class) {
                return true;
            }
        }

        return false;
    }

    private function backgroundTaskQueueServiceId(ContainerBuilder $container, string $pluginId): string
    {
        $serviceId = 'app.background_task_queue.'.$pluginId;

        if (!$container->hasDefinition($serviceId)) {
            // A raw `new PluginId($pluginId)` argument here would make PhpDumper fail to compile
            // the container ("Unable to dump a service container if a parameter is an object") the
            // moment any plugin actually consumes this service — PhpDumper only knows how to
            // inline a Reference/Definition/scalar as a constructor argument, not an arbitrary
            // object instance. Wrapping it as its own inline Definition keeps it dumpable.
            $container->setDefinition($serviceId, (new Definition(BackgroundTaskQueue::class))
                ->setArguments([
                    (new Definition(PluginId::class))->setArguments([$pluginId]),
                    new Reference(MessageBusInterface::class),
                ])
                ->setPublic(false));
        }

        return $serviceId;
    }
}
