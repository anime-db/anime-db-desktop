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

use AnimeDb\PluginContracts\Manifest\OwnManifestInterface;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\OwnManifest;
use App\Service\Plugin\PluginNamespace;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives every plugin service that type-hints {@see OwnManifestInterface} in its constructor an
 * {@see OwnManifest} instance carrying *that plugin's own* id/name/version (issue #323) — the
 * motivating case is a plugin building a `User-Agent` string from its own version, which today
 * means reading `manifest.json` off disk by a fragile relative path. Same class-to-plugin
 * matching as {@see SettingsStoreScopePass}/{@see PluginDataStoreScopePass}: a service belongs to
 * a plugin when its class lives under that plugin's namespace (`AnimeDb\Plugins\<StudlyId>\`).
 * Only adds the binding to definitions whose constructor actually declares an
 * `OwnManifestInterface` parameter: Symfony's `ResolveBindingsPass` hard-fails compilation on a
 * binding with no matching argument anywhere on that definition ("may be unused... should be
 * removed"), and most plugin services have no reason to want one.
 *
 * Unlike {@see SettingsStoreScopePass} and {@see PluginDataStoreScopePass}, which bind a *lazy*
 * per-plugin service (settings and OAuth tokens mutate at runtime without a container rebuild),
 * this pass bakes the plugin's id/name/version as scalar constructor arguments directly into the
 * per-plugin {@see OwnManifest} definition at compile time. A plugin's manifest only ever changes
 * on install/update, and both fully reset and rebuild the container (Atomic Cache Swap) before
 * the plugin is reachable at all — so a value baked into the container can never be staler than
 * the container itself, and a runtime-resolved lookup would be pure overhead.
 *
 * {@see InstalledPluginsRegistry::get()} is expected to always resolve here: the plugin id being
 * looked up came from the very same registry {@see self::pluginNamespacePrefixes()} built its
 * namespace prefixes from. A `null` would mean the registry disagreed with itself mid-compile, so
 * that case throws a {@see \LogicException} instead of being silently guarded against.
 */
final class OwnManifestScopePass implements CompilerPassInterface
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

            if (!class_exists($class) || !$this->wantsOwnManifest($class)) {
                continue;
            }

            $definition->setBindings([
                ...$definition->getBindings(),
                OwnManifestInterface::class => new Reference($this->ownManifestServiceId($container, $pluginId)),
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
    private function wantsOwnManifest(string $class): bool
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            return false;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === OwnManifestInterface::class) {
                return true;
            }
        }

        return false;
    }

    private function ownManifestServiceId(ContainerBuilder $container, string $pluginId): string
    {
        $serviceId = 'app.own_manifest.'.$pluginId;

        if (!$container->hasDefinition($serviceId)) {
            $plugin = $this->registry->get(new PluginId($pluginId))
                ?? throw new \LogicException(\sprintf('Plugin "%s" not found in the registry while binding its own manifest.', $pluginId));

            $container->setDefinition($serviceId, (new Definition(OwnManifest::class))
                ->setArguments([$plugin->manifest->id, $plugin->manifest->name, $plugin->manifest->version])
                ->setPublic(false));
        }

        return $serviceId;
    }
}
