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

namespace App\Service\PluginContracts;

use App\Service\Market\MarketPlugin;
use App\Service\Market\PluginRegistry;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/**
 * Cross-checks the raw, signature-verified registry JSON against its parsed form
 * ({@see PluginRegistry::fromJson()}), which silently drops plugins it cannot parse and silently
 * turns an unparseable `plugin_contracts` pin into `null` ("compatible"). Neither may go unnoticed
 * by the lag check, so: a plugin present only in the raw data is reported as not parsed, and a
 * pin that is non-null in the raw data but `null` after parsing is a
 * {@see PluginContractsCheckException}. Pure: no I/O.
 */
final class PluginContractPinsExtractor
{
    /**
     * Fails on the first plugin-level problem, as a pre-release check must: any of them turns it red.
     *
     * @return list<PluginContractPins>
     *
     * @throws PluginContractsCheckException
     */
    public function extract(string $rawRegistryJson, PluginRegistry $registry): array
    {
        $extraction = $this->extractAll($rawRegistryJson, $registry);

        if ($extraction->entriesWithoutId > 0) {
            throw new PluginContractsCheckException('The raw registry has a plugin entry without a string "id".');
        }

        foreach ($extraction->plugins as $plugin) {
            if ($plugin->problem !== null) {
                throw new PluginContractsCheckException($plugin->problem);
            }
        }

        return $extraction->plugins;
    }

    /**
     * Only a registry that cannot be read at all throws; a problem with one plugin is reported on
     * that plugin ({@see PluginContractPins::$problem}) so it cannot block the others.
     *
     * @throws PluginContractsCheckException
     */
    public function extractAll(string $rawRegistryJson, PluginRegistry $registry): PluginContractsExtraction
    {
        try {
            $data = json_decode($rawRegistryJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new PluginContractsCheckException('The raw registry is not valid JSON.', previous: $exception);
        }

        $rawPlugins = \is_array($data) ? ($data['plugins'] ?? null) : null;
        if (!\is_array($rawPlugins)) {
            throw new PluginContractsCheckException('The raw registry has no "plugins" list.');
        }

        $parsedById = [];
        foreach ($registry->plugins() as $plugin) {
            $parsedById[(string) $plugin->id] = $plugin;
        }

        $result = [];
        $entriesWithoutId = 0;
        foreach ($rawPlugins as $rawPlugin) {
            if (!\is_array($rawPlugin) || !\is_string($rawPlugin['id'] ?? null)) {
                ++$entriesWithoutId;

                continue;
            }

            $id = $rawPlugin['id'];
            $parsed = $parsedById[$id] ?? null;

            $result[] = $parsed !== null
                ? $this->fromParsed($id, $parsed, $rawPlugin)
                : $this->fromRaw($id, $rawPlugin);
        }

        return new PluginContractsExtraction($result, $entriesWithoutId);
    }

    /**
     * @param array<mixed> $rawPlugin
     */
    private function fromParsed(string $id, MarketPlugin $plugin, array $rawPlugin): PluginContractPins
    {
        $rawPinsByVersion = [];
        foreach (\is_array($rawPlugin['versions'] ?? null) ? $rawPlugin['versions'] : [] as $rawVersion) {
            if (\is_array($rawVersion) && \is_string($rawVersion['version'] ?? null)) {
                $rawPinsByVersion[$rawVersion['version']] = $rawVersion['plugin_contracts'] ?? null;
            }
        }

        $pins = [];
        foreach ($plugin->versions as $version) {
            if ($version->pluginContracts === null && ($rawPinsByVersion[$version->version] ?? null) !== null) {
                return new PluginContractPins($id, true, [], null, null, \sprintf('Plugin "%s" version %s has a plugin_contracts pin that the app cannot parse.', $id, $version->version));
            }

            $pins[] = $version->pluginContracts;
        }

        $latest = $plugin->latestVersion();

        return new PluginContractPins($id, true, $pins, $latest->version, $latest->pluginContracts);
    }

    /**
     * @param array<mixed> $rawPlugin
     */
    private function fromRaw(string $id, array $rawPlugin): PluginContractPins
    {
        $pinsByVersion = [];
        foreach (\is_array($rawPlugin['versions'] ?? null) ? $rawPlugin['versions'] : [] as $rawVersion) {
            if (!\is_array($rawVersion) || !\is_string($rawVersion['version'] ?? null)) {
                continue;
            }

            try {
                (new VersionParser())->normalize($rawVersion['version']);
            } catch (\UnexpectedValueException) {
                continue;
            }

            $pin = $rawVersion['plugin_contracts'] ?? null;
            $pinsByVersion[$rawVersion['version']] = \is_string($pin) ? $pin : null;
        }

        $latestVersion = $pinsByVersion === [] ? null : Semver::rsort(array_map(strval(...), array_keys($pinsByVersion)))[0];

        return new PluginContractPins($id, false, [], $latestVersion, $latestVersion !== null ? $pinsByVersion[$latestVersion] : null);
    }
}
