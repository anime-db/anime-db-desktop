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

use AnimeDb\PluginContracts\Folder\RecordFolderWriterInterface;
use AnimeDb\PluginContracts\Manifest\PluginType;
use App\Entity\ValueObject\PluginId;
use App\Service\Folder\RecordFolderWriter;
use App\Service\Plugin\Exception\WriterRequiresLocalPluginException;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginNamespace;
use App\Service\Storage\StorageMarkerService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives every plugin service that type-hints {@see RecordFolderWriterInterface} in its constructor
 * a {@see RecordFolderWriter} bound to *that plugin's own* id (issue #1065) — the same mechanics as
 * {@see PluginDataStoreScopePass}: the plugin never names its own id, so the host supplies the
 * right instance, keyed off the plugin namespace the consuming class lives in.
 *
 * Only `local` plugins get a writer: an `integration` plugin has network access and gains nothing
 * from writing into the user's folders. A service of any other plugin type asking for one fails the
 * container build with {@see WriterRequiresLocalPluginException}, so the plugin is not loaded.
 */
final class RecordFolderWriterScopePass implements CompilerPassInterface
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

            if (!class_exists($class) || !$this->wantsRecordFolderWriter($class)) {
                continue;
            }

            $this->assertLocal($pluginId, $class);

            $definition->setBindings([
                ...$definition->getBindings(),
                RecordFolderWriterInterface::class => new Reference($this->recordFolderWriterServiceId($container, $pluginId)),
            ]);
        }
    }

    private function assertLocal(string $pluginId, string $class): void
    {
        foreach ($this->registry->all() as $plugin) {
            if ((string) $plugin->id === $pluginId && $plugin->manifest->type !== PluginType::Local) {
                throw new WriterRequiresLocalPluginException($pluginId, $class, $plugin->manifest->type->value);
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

    /** @param class-string $class */
    private function wantsRecordFolderWriter(string $class): bool
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return false;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === RecordFolderWriterInterface::class) {
                return true;
            }
        }

        return false;
    }

    private function recordFolderWriterServiceId(ContainerBuilder $container, string $pluginId): string
    {
        $serviceId = 'app.record_folder_writer.'.$pluginId;

        if (!$container->hasDefinition($serviceId)) {
            // A raw `new PluginId($pluginId)` argument here would make PhpDumper fail to compile
            // the container ("Unable to dump a service container if a parameter is an object") the
            // moment any plugin actually consumes this service — PhpDumper only knows how to
            // inline a Reference/Definition/scalar as a constructor argument, not an arbitrary
            // object instance. Wrapping it as its own inline Definition keeps it dumpable.
            $container->setDefinition($serviceId, (new Definition(RecordFolderWriter::class))
                ->setArguments([
                    (new Definition(PluginId::class))->setArguments([$pluginId]),
                    new Reference('doctrine'),
                    new Reference(StorageMarkerService::class),
                    '%app.media_dir%',
                ])
                ->setPublic(false));
        }

        return $serviceId;
    }
}
