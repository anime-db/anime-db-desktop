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
 *
 * {@see self::synchronized()} serializes {@see self::reconcile()} against itself and against the
 * installer/remover operations that call it, across FrankenPHP worker threads/processes (issue
 * #420) — see {@see PluginFileLock}. Reads below (`all()`, `enabled()`, `get()`, `has()`) stay
 * lock-free: the index file's `rename()`-based publish already guarantees a reader sees a wholly
 * old or wholly new version of it.
 */
final class InstalledPluginsRegistry
{
    public function __construct(
        private readonly string $pluginsDir,
        private readonly PluginsConfigStore $pluginsConfigStore,
        private readonly LoggerInterface $logger,
        private readonly ManifestParser $manifestParser = new ManifestParser(),
        /**
         * Safe mode (issue #403): the native layer sets SAFE_MODE=1 after repeated failed
         * startups to recover from a plugin that crashes the kernel bootstrap. When true, every
         * read below reports no installed plugins without touching the index file at all — see
         * {@see self::readIndex()}.
         */
        private readonly bool $safeMode = false,
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
        $this->synchronized(function (): void {
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

                // A plugin is always installed at %plugins_dir%/<manifest id>, so a directory whose
                // basename does not equal its own manifest id is not a real installation. The case
                // that matters is a "<id>.removing-<hex>" staging directory a failed
                // PluginDirectoryRemover::remove() left behind (issue #420): registering it would
                // resurrect a "removed" plugin, and — since scandir() lists "<id>" before
                // "<id>.removing-<hex>" — a stale staged copy would even overwrite a fresh
                // reinstall's entry. Skip anything whose basename does not match its manifest id.
                if (basename($pluginDir) !== $manifest->id) {
                    $this->logger->warning('Skipping plugin directory whose name does not match its manifest id.', [
                        'pluginDir' => $pluginDir,
                        'manifestId' => $manifest->id,
                    ]);

                    continue;
                }

                $entries[$manifest->id] = [
                    'installPath' => $pluginDir,
                    'manifest' => $this->manifestToArray($manifest),
                ];
            }

            $this->writeIndex($entries);
        });
    }

    /**
     * Runs $callback under the same exclusive, process-wide lock {@see self::reconcile()} itself
     * acquires — {@see ZipPluginInstaller::install()}/`update()` and {@see PluginRemover::remove()}
     * wrap their whole operation in this so a concurrent request can never observe, or race against,
     * a half-finished install/update/remove on the shared plugin filesystem layer (issue #420).
     * Reentrant: calling this from within an already-synchronized callback (reconcile() is called by
     * all three of the above once their own file moves are done) does not deadlock — see
     * {@see PluginFileLock}.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function synchronized(callable $callback): mixed
    {
        return PluginFileLock::synchronized($this->lockPath(), $callback);
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
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            $path = $this->pluginsDir.\DIRECTORY_SEPARATOR.$entry;
            if ($entry !== '.' && $entry !== '..' && is_dir($path)) {
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

        if ($contents === false) {
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
        if ($this->safeMode) {
            return [];
        }

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

            try {
                $manifest = $this->manifestFromArray($entry['manifest']);
                $plugins[$id] = new InstalledPlugin($manifest, $entry['installPath'], $this->isEnabled($id));
            } catch (\Throwable $exception) {
                $this->logger->error('Skipping installed plugin with an invalid index entry.', [
                    'pluginId' => $id,
                    'exception' => $exception,
                ]);

                continue;
            }
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

        // A random suffix (rather than the fixed name this used to be) plus LOCK_EX means a
        // concurrent writer that somehow bypassed self::synchronized() can no longer interleave
        // with this one on the same .tmp file and publish a syntactically broken index — see the
        // class docblock and issue #420. self::synchronized() already serializes every caller
        // that goes through reconcile(), so this is defence in depth, not the primary guard.
        $tmpPath = $this->indexPath().'.tmp.'.bin2hex(random_bytes(8));
        if (file_put_contents($tmpPath, $contents, \LOCK_EX) === false) {
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

    private function lockPath(): string
    {
        return $this->pluginsDir.\DIRECTORY_SEPARATOR.'.plugins.lock';
    }
}
