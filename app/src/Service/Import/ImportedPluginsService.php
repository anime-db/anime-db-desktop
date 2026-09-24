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

namespace App\Service\Import;

use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Market\PluginRegistryCache;
use App\Service\Plugin\InstalledPluginsRegistry;

/**
 * Reads `userData/import-applied.json` — the `manifest.json` native/supervisor/import-apply.js
 * (issue #726) best-effort carried over from a successfully applied catalog import — and resolves
 * each plugin it lists to a status for the /settings/backup "plugins from the imported archive"
 * block: already {@see ImportedPluginStatus::Installed}, {@see ImportedPluginStatus::AvailableInMarket}
 * / {@see ImportedPluginStatus::ManualInstall} (resolved against a local market registry cache), or
 * {@see ImportedPluginStatus::CheckMarket} when no cache is available to resolve against at all.
 *
 * This is purely informational: nothing here installs a plugin, resolves it over the network, or
 * triggers a registry refresh — see {@see PluginRegistryCache::getCachedRegistry()}, which only
 * ever reads the last cache Electron's `app:market:refresh` happened to store, ahead of time.
 *
 * `import-applied.json` is treated as fully untrusted input (issue #726): it is a plain file
 * transplanted by a `fs.renameSync()` in the native layer, from a `manifest.json` that itself
 * only ever passed {@see CatalogStageService}'s archive-shape checks, never a check on `plugins`
 * itself. An unreadable or malformed file, a `plugins` field that is not a list, a list entry that
 * is not an object, or an `id`/`name`/`version` that is not a string are all treated the same way
 * a malformed entry is treated everywhere else this codebase reads untrusted plugin data (e.g.
 * {@see \App\Controller\PluginAssetController}, {@see \App\Service\Market\PluginRegistry}): the
 * one bad entry — or, for an unreadable file, the whole list — is dropped rather than surfacing an
 * exception or a 500.
 */
final class ImportedPluginsService
{
    /**
     * Only the first 100 entries of `plugins` are ever resolved — a manifest listing far more
     * plugins than any real installation could ever have is far more likely to be a corrupted or
     * hostile file than a genuine catalog, and resolving thousands of entries against the
     * installed-plugin set on every /settings/backup GET has no bound otherwise.
     */
    private const int MAX_ENTRIES = 100;

    /**
     * A string field longer than this is treated the same as a wrong type: the entry is dropped.
     * Both `id` (a lowercase "vendor-name" slug) and `name`/`version` (short, one-line display
     * strings) are always far shorter than this in a genuine manifest.
     */
    private const int MAX_STRING_LENGTH = 200;

    public function __construct(
        private readonly PluginRegistryCache $registryCache,
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly string $importAppliedPath,
    ) {
    }

    /**
     * @return list<ImportedPlugin>
     */
    public function list(): array
    {
        $pluginsData = $this->readPluginsField();
        if ($pluginsData === null) {
            return [];
        }

        // Read once, regardless of how many entries plugins() below resolves against it —
        // InstalledPluginsRegistry::has()/get() each re-read and re-parse the whole index on
        // every call and must never be called in a loop (see that class's own docblock).
        $installedIds = [];
        foreach ($this->installedPlugins->all() as $plugin) {
            $installedIds[(string) $plugin->id] = true;
        }

        $registry = $this->registryCache->getCachedRegistry();
        $registryIds = null;
        if ($registry !== null) {
            $registryIds = [];
            foreach ($registry->plugins() as $marketPlugin) {
                $registryIds[(string) $marketPlugin->id] = true;
            }
        }

        $result = [];
        foreach (\array_slice($pluginsData, 0, self::MAX_ENTRIES) as $entry) {
            $plugin = $this->parseEntry($entry, $installedIds, $registryIds);
            if ($plugin !== null) {
                $result[] = $plugin;
            }
        }

        return $result;
    }

    /**
     * Best-effort removal of `import-applied.json`, called once the block has nothing left to
     * show it for (see {@see \App\Controller\Settings\BackupController::index()}) or the user
     * dismisses it explicitly. A missing file is not an error.
     */
    public function dismiss(): void
    {
        @unlink($this->importAppliedPath);
    }

    /**
     * @return list<mixed>|null null whenever the file is missing, unreadable, not valid JSON, or
     *                          its "plugins" field is not a list at all — the whole block is
     *                          then treated as absent rather than partially shown
     */
    private function readPluginsField(): ?array
    {
        if (!is_file($this->importAppliedPath)) {
            return null;
        }

        $contents = file_get_contents($this->importAppliedPath);
        if ($contents === false) {
            return null;
        }

        $manifest = json_decode($contents, true);
        if (!\is_array($manifest)) {
            return null;
        }

        $plugins = $manifest['plugins'] ?? null;

        return \is_array($plugins) && array_is_list($plugins) ? $plugins : null;
    }

    /**
     * @param array<string, true>      $installedIds
     * @param array<string, true>|null $registryIds  null means no local registry cache exists
     */
    private function parseEntry(mixed $entry, array $installedIds, ?array $registryIds): ?ImportedPlugin
    {
        if (!\is_array($entry)) {
            return null;
        }

        $id = $entry['id'] ?? null;
        $name = $entry['name'] ?? null;
        $version = $entry['version'] ?? null;

        if (!$this->isValidOptionalString($id, required: true)
            || !$this->isValidOptionalString($name, required: false)
            || !$this->isValidOptionalString($version, required: false)
        ) {
            return null;
        }

        try {
            $pluginId = new PluginId($id);
        } catch (InvalidPluginIdException) {
            return null;
        }

        $key = (string) $pluginId;
        $status = match (true) {
            isset($installedIds[$key]) => ImportedPluginStatus::Installed,
            $registryIds === null => ImportedPluginStatus::CheckMarket,
            isset($registryIds[$key]) => ImportedPluginStatus::AvailableInMarket,
            default => ImportedPluginStatus::ManualInstall,
        };

        return new ImportedPlugin($key, \is_string($name) ? $name : $key, $status);
    }

    private function isValidOptionalString(mixed $value, bool $required): bool
    {
        if ($value === null) {
            return !$required;
        }

        return \is_string($value) && \strlen($value) <= self::MAX_STRING_LENGTH;
    }
}
