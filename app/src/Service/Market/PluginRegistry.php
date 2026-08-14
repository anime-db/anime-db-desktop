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

namespace App\Service\Market;

use App\Entity\ValueObject\PluginId;
use App\Service\Market\Exception\InvalidPluginRegistryContentException;

/**
 * A parsed, already signature-verified `plugins-registry.json` (see
 * {@see PluginRegistryLoader}). Only the fields this application currently needs are extracted —
 * `sequence` (anti-rollback), `asset_mirrors` (URL templates for asset downloads) and, per
 * plugin, the `sha256` of each published version — full per-version `manifest`/`core` parsing
 * belongs to the market storefront (issue #220), not to this fetch-and-verify layer.
 */
final class PluginRegistry
{
    /**
     * @param list<string>                         $assetMirrors              URL templates containing
     *                                                                        the `<id>`/`<version>`/`<file>` macros
     * @param array<string, array<string, string>> $sha256ByVersionByPluginId pluginId => [version => sha256]
     */
    private function __construct(
        public readonly int $sequence,
        public readonly array $assetMirrors,
        private readonly array $sha256ByVersionByPluginId,
    ) {
    }

    /**
     * @throws InvalidPluginRegistryContentException if $json is not valid JSON, or is missing
     *                                               one of the fields the registry format requires
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidPluginRegistryContentException('plugins-registry.json is not valid JSON.', previous: $exception);
        }

        if (!\is_array($data)) {
            throw new InvalidPluginRegistryContentException('plugins-registry.json must decode to a JSON object.');
        }

        $sequence = $data['sequence'] ?? null;
        if (!\is_int($sequence) || $sequence < 1) {
            throw new InvalidPluginRegistryContentException('plugins-registry.json is missing a valid positive-integer "sequence" field.');
        }

        $assetMirrors = $data['asset_mirrors'] ?? null;
        if (!\is_array($assetMirrors) || !array_is_list($assetMirrors)) {
            throw new InvalidPluginRegistryContentException('plugins-registry.json is missing a valid "asset_mirrors" list.');
        }

        $plugins = $data['plugins'] ?? null;
        if (!\is_array($plugins) || !array_is_list($plugins)) {
            throw new InvalidPluginRegistryContentException('plugins-registry.json is missing a valid "plugins" list.');
        }

        return new self($sequence, array_map(strval(...), $assetMirrors), self::extractSha256Map($plugins));
    }

    public function findVersionSha256(PluginId $pluginId, string $version): ?string
    {
        return $this->sha256ByVersionByPluginId[(string) $pluginId][$version] ?? null;
    }

    /**
     * @param list<mixed> $plugins
     *
     * @return array<string, array<string, string>>
     */
    private static function extractSha256Map(array $plugins): array
    {
        $sha256ByVersionByPluginId = [];

        foreach ($plugins as $plugin) {
            if (!\is_array($plugin) || !\is_string($plugin['id'] ?? null) || !\is_array($plugin['versions'] ?? null)) {
                continue;
            }

            $sha256ByVersion = [];
            foreach ($plugin['versions'] as $version) {
                if (\is_array($version) && \is_string($version['version'] ?? null) && \is_string($version['sha256'] ?? null)) {
                    $sha256ByVersion[$version['version']] = $version['sha256'];
                }
            }

            $sha256ByVersionByPluginId[$plugin['id']] = $sha256ByVersion;
        }

        return $sha256ByVersionByPluginId;
    }
}
