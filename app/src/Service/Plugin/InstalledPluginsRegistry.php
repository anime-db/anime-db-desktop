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

namespace App\Service\Plugin;

use AnimeDb\PluginContracts\Manifest\InvalidManifestException;
use AnimeDb\PluginContracts\Manifest\InvalidManifestJsonException;
use AnimeDb\PluginContracts\Manifest\Manifest;
use AnimeDb\PluginContracts\Manifest\ManifestParser;
use AnimeDb\PluginContracts\Manifest\ManifestRequirements;
use AnimeDb\PluginContracts\Manifest\ManifestValidationError;
use AnimeDb\PluginContracts\Manifest\PluginType;
use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\Exception\InstalledPluginsRegistryException;
use App\Service\Plugin\Exception\InvalidInstalledPluginException;
use Psr\Log\LoggerInterface;

/**
 * Read-first source of truth for which plugins are installed, backed by a compact, pre-parsed
 * index file (`installed-plugins.php`, next to the plugin directories themselves) rather than
 * `manifest.json` files scanned and parsed on every read. `Kernel::boot()` runs on every
 * FrankenPHP worker request (see architecture docs), so a plugin manager built on top of this
 * registry (issue #218) needs a source it can read cheaply on every single one of them, the same
 * way `config/bundles.php` statically lists bundles instead of scanning for them.
 *
 * {@see self::reconcile()} is the only thing that touches `manifest.json` files and rebuilds the
 * index — called by the future installer after every install/remove/enable/disable mutation
 * (issues #220-225), and safe to call again any time the index is suspected to be stale (a
 * plugin directory was removed by hand, etc.).
 *
 * `enabled` is deliberately not part of the persisted index: {@see PluginsConfigStore} is already
 * the single source of truth for it (issue #219), so every read here re-derives it from there
 * instead of risking the two falling out of sync.
 */
final class InstalledPluginsRegistry
{
    public function __construct(
        private readonly string $pluginsDir,
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly LoggerInterface $logger,
        private readonly ManifestParser $manifestParser = new ManifestParser(),
    ) {
    }

    /**
     * @return list<InstalledPlugin>
     */
    public function all(): array
    {
        return array_values($this->readIndex());
    }

    /**
     * @return list<InstalledPlugin>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->readIndex(),
            static fn (InstalledPlugin $plugin): bool => $plugin->enabled,
        ));
    }

    public function get(PluginId $id): ?InstalledPlugin
    {
        return $this->readIndex()[(string) $id] ?? null;
    }

    public function has(PluginId $id): bool
    {
        return $this->get($id) !== null;
    }

    /**
     * Scans {@see self::$pluginsDir} for plugin directories, parses each one's `manifest.json`
     * and rewrites the index from scratch. A directory whose manifest is missing or invalid is
     * skipped and logged; it does not abort the scan for the rest of the plugins.
     */
    public function reconcile(): void
    {
        $entries = [];

        foreach ($this->scanPluginDirectories() as $pluginDir) {
            try {
                $manifest = $this->parseManifest($pluginDir);
            } catch (InvalidInstalledPluginException $exception) {
                $this->logger->error('Skipping installed plugin with an invalid manifest.json.', [
                    'pluginDir' => $pluginDir,
                    'errors' => array_map(
                        static fn (ManifestValidationError $error): array => [
                            'field' => $error->field,
                            'message' => $error->message,
                        ],
                        $exception->errors,
                    ),
                    'exception' => $exception,
                ]);

                continue;
            }

            $entries[$manifest->id] = [
                'installPath' => $pluginDir,
                'manifest' => $this->manifestToArray($manifest),
            ];
        }

        $this->writeIndex($entries);
    }

    /**
     * @return iterable<string> absolute paths of plugin directories
     */
    private function scanPluginDirectories(): iterable
    {
        if (!is_dir($this->pluginsDir)) {
            return;
        }

        $entries = scandir($this->pluginsDir);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            $path = $this->pluginsDir.\DIRECTORY_SEPARATOR.$entry;
            if ('.' !== $entry && '..' !== $entry && is_dir($path)) {
                yield $path;
            }
        }
    }

    /**
     * @throws InvalidInstalledPluginException
     */
    private function parseManifest(string $pluginDir): Manifest
    {
        $manifestPath = $pluginDir.\DIRECTORY_SEPARATOR.'manifest.json';
        $contents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;

        if (false === $contents) {
            throw new InvalidInstalledPluginException($pluginDir, []);
        }

        try {
            return $this->manifestParser->parse($contents);
        } catch (InvalidManifestException $exception) {
            throw new InvalidInstalledPluginException($pluginDir, $exception->errors, $exception);
        } catch (InvalidManifestJsonException $exception) {
            throw new InvalidInstalledPluginException($pluginDir, [], $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestToArray(Manifest $manifest): array
    {
        return [
            'id' => $manifest->id,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'type' => $manifest->type->value,
            'require' => [
                'core' => $manifest->require->core,
                'php' => $manifest->require->php,
                'pluginContracts' => $manifest->require->pluginContracts,
            ],
            'description' => $manifest->description,
            'author' => $manifest->author,
            'features' => $manifest->features,
            'locales' => $manifest->locales,
            'updateUrl' => $manifest->updateUrl,
        ];
    }

    /**
     * @param array<string, mixed> $data as produced by {@see self::manifestToArray()}
     */
    private function manifestFromArray(array $data): Manifest
    {
        $type = PluginType::from($data['type']);
        $require = $data['require'];

        return new Manifest(
            id: $data['id'],
            name: $data['name'],
            version: $data['version'],
            type: $type,
            require: new ManifestRequirements(
                core: $require['core'],
                php: $require['php'],
                pluginContracts: $require['pluginContracts'],
            ),
            description: $data['description'],
            author: $data['author'],
            features: $data['features'],
            locales: $data['locales'],
            updateUrl: $data['updateUrl'],
        );
    }

    /**
     * @return array<string, InstalledPlugin> keyed by plugin id
     */
    private function readIndex(): array
    {
        $indexPath = $this->indexPath();
        if (!is_file($indexPath)) {
            return [];
        }

        $raw = require $indexPath;
        if (!\is_array($raw)) {
            return [];
        }

        $plugins = [];
        foreach ($raw as $id => $entry) {
            if (!\is_string($id) || !\is_array($entry) || !\is_array($entry['manifest'] ?? null) || !\is_string($entry['installPath'] ?? null)) {
                continue;
            }

            $manifest = $this->manifestFromArray($entry['manifest']);
            $plugins[$id] = new InstalledPlugin($manifest, $entry['installPath'], $this->isEnabled($id));
        }

        return $plugins;
    }

    /**
     * A plugin without a recorded "enabled" setting yet is treated as active, same convention as
     * FillerRegistry/WidgetActiveTrait: plugins.json only ever records an explicit "false" once
     * the user disables it.
     */
    private function isEnabled(string $id): bool
    {
        $settings = $this->pluginsConfigStore->getPluginSettings(new PluginId($id));

        return (bool) ($settings['enabled'] ?? true);
    }

    /**
     * @param array<string, array{installPath: string, manifest: array<string, mixed>}> $entries
     */
    private function writeIndex(array $entries): void
    {
        $directory = \dirname($this->indexPath());
        if (!is_dir($directory) && !mkdir($directory, recursive: true) && !is_dir($directory)) {
            throw new InstalledPluginsRegistryException(\sprintf('Unable to create directory "%s".', $directory));
        }

        $contents = "<?php\n\nreturn ".var_export($entries, true).";\n";

        $tmpPath = $this->indexPath().'.tmp';
        if (false === file_put_contents($tmpPath, $contents)) {
            throw new InstalledPluginsRegistryException(\sprintf('Unable to write "%s".', $tmpPath));
        }

        // rename() on Windows overwrites an existing destination (unlike a bare POSIX rename()
        // pre-8.0), so this stays atomic on the app's only supported platform.
        rename($tmpPath, $this->indexPath());
    }

    private function indexPath(): string
    {
        return $this->pluginsDir.\DIRECTORY_SEPARATOR.'installed-plugins.php';
    }
}
