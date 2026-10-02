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

use AnimeDb\PluginContracts\Filler\FillerInterface;
use App\Entity\ValueObject\PluginId;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Lists installed, active {@see FillerInterface} plugins for two callers: the per-field
 * "fill in from source" dropdown on the anime edit form (several plugins may support the same
 * field) and the bulk-fill flow, which already knows which plugin to use (chosen by the
 * storage scan or by the user) and just needs that one instance, not a scan of all of them.
 *
 * `FillerInterface` lives in the read-only `anime-db/plugin-contracts` package, so it cannot
 * carry `#[AutoconfigureTag]` the way the local {@see \App\Service\Storage\Search\SearchByPluginInterface}
 * does — it is tagged 'app.filler' at compile time instead, by
 * {@see DependencyInjection\Compiler\TagPluginServicesPass} (issue #278).
 *
 * `indexAttribute: 'id'` keys the injected iterable by each filler's own {@see PluginId}: the
 * compiler pass derives it from the plugin's namespace and puts it on the tag as the `id`
 * attribute, matching the same string {@see PluginsConfigStore} already keys `plugins.json` by.
 * Neither `ExternalIdResolutionInterface` nor `FillerInterface` exposes a way to ask an arbitrary
 * instance for its own `PluginId` directly — the closest thing, `resolveExternalId()`, resolves an
 * id on an external source from catalog URLs, not the plugin's own identity.
 *
 * The `features.filler ?? true` activity check itself lives in {@see FillerActiveTrait}, shared
 * with {@see \App\Service\Storage\Search\SearchByPluginChain} (issue #280).
 */
final class FillerRegistry
{
    use FillerActiveTrait;

    /** @param iterable<string, FillerInterface> $fillers */
    public function __construct(
        #[AutowireIterator('app.filler', indexAttribute: 'id')]
        private readonly iterable $fillers,
        private readonly PluginsConfigStore $pluginsConfigStore,
    ) {
    }

    /**
     * @return list<FillerInterface> active plugins that report $field among getFillableFields()
     */
    public function findByField(string $field): array
    {
        return array_values($this->findWithIdByField($field));
    }

    /**
     * Same filter as {@see findByField()}, but keyed by each filler's own {@see PluginId} -
     * the per-field "fill from source" button/dropdown (issue #234) needs the id to link to
     * (route parameter, option value), not just the filler instance.
     *
     * @return array<string, FillerInterface> pluginId string => active filler supporting $field
     */
    public function findWithIdByField(string $field): array
    {
        $result = [];
        foreach ($this->fillers as $id => $filler) {
            if ($this->isFillerActive(new PluginId((string) $id)) && \in_array($field, $filler->getFillableFields(), true)) {
                $result[(string) $id] = $filler;
            }
        }

        return $result;
    }

    /**
     * Resolves a single, already-known plugin without the caller having to enumerate every
     * registered filler itself. Returns null both when no filler is registered under this id
     * and when the matching plugin is installed but disabled (features.filler false).
     */
    public function findByPluginId(PluginId $pluginId): ?FillerInterface
    {
        $filler = iterator_to_array($this->fillers)[(string) $pluginId] ?? null;

        return $filler !== null && $this->isFillerActive($pluginId) ? $filler : null;
    }

    /**
     * Every active filler plugin (issue #833), keyed by its own {@see PluginId} — the "search in
     * plugins" screen queries each one of these separately, one HTMX request per group, rather
     * than going through {@see \App\Service\Storage\Search\SearchByPluginChain}, which stops at
     * the first plugin with a non-empty result and also admits "pure" search plugins with no
     * findById() to fall back on.
     *
     * @return array<string, FillerInterface> pluginId string => active filler
     */
    public function findAllActive(): array
    {
        $result = [];
        foreach ($this->fillers as $id => $filler) {
            if ($this->isFillerActive(new PluginId((string) $id))) {
                $result[(string) $id] = $filler;
            }
        }

        return $result;
    }

    /**
     * The three-way "why is there no active filler plugin" signal (issue #833) a caller with an
     * empty {@see self::findAllActive()} needs to point the user at the right next step: install
     * one from the market, turn the whole plugin back on, or just flip its `features.filler`
     * toggle. Only meaningful when {@see self::findAllActive()} is empty — a caller with at least
     * one active filler has no reason to call this.
     *
     * Picks the first installed filler plugin that is enabled as a whole but has its filler
     * feature switched off, so a mix of "whole-plugin disabled" and "feature disabled" installs
     * favors the state with an actionable settings page over the one that only points at the
     * installed-plugins list.
     */
    public function fillerAvailability(InstalledPluginsRegistry $installedPlugins, SettingsPageRegistry $settingsPages): FillerAvailability
    {
        $fillers = iterator_to_array($this->fillers);
        if ($fillers === []) {
            return FillerAvailability::notInstalled();
        }

        foreach (array_keys($fillers) as $id) {
            $pluginId = new PluginId((string) $id);
            $installed = $installedPlugins->get($pluginId);
            if ($installed === null || !$installed->enabled || $this->isFillerActive($pluginId)) {
                continue;
            }

            $page = $settingsPages->find($pluginId);

            return $page !== null ? FillerAvailability::disabledWithSettingsPage($pluginId) : FillerAvailability::disabledNoSettingsPage();
        }

        return FillerAvailability::disabledNoSettingsPage();
    }
}
