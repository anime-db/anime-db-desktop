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

use App\Service\Market\Exception\InvalidMarketSnapshotContentException;

/**
 * The market storefront's own on-disk artifact (issue #436, part of the market snapshot epic
 * #435): a flattened, ready-to-render copy of a {@see PluginRegistry} plus per-plugin resolution
 * results ({@see MarketSnapshotPlugin}) against a specific core version, built by
 * {@see MarketSnapshotBuilder} and persisted by {@see MarketSnapshotCache}.
 *
 * Deliberately carries no fetch/verify/anti-rollback concerns of its own — those stay on
 * {@see PluginRegistryLoader}/{@see PluginRegistry} upstream of the builder. This class is only
 * the resulting flat data plus its own JSON (de)serialization.
 */
final class MarketSnapshot
{
    /**
     * @param list<string>               $assetMirrors URL templates carried over from {@see PluginRegistry::$assetMirrors}
     * @param list<MarketSnapshotPlugin> $plugins
     */
    public function __construct(
        public readonly string $coreVersion,
        public readonly int $sequence,
        public readonly array $assetMirrors,
        public readonly array $plugins,
    ) {
    }

    /**
     * @return array{core_version: string, sequence: int, asset_mirrors: list<string>, plugins: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'core_version' => $this->coreVersion,
            'sequence' => $this->sequence,
            'asset_mirrors' => $this->assetMirrors,
            'plugins' => array_map(static fn (MarketSnapshotPlugin $plugin): array => $plugin->toArray(), $this->plugins),
        ];
    }

    /**
     * @throws InvalidMarketSnapshotContentException if $json is not valid JSON, or decodes to a
     *                                               value missing one of the fields a snapshot requires
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidMarketSnapshotContentException('Market snapshot is not valid JSON.', previous: $exception);
        }

        if (!\is_array($data)
            || !\is_string($data['core_version'] ?? null)
            || !\is_int($data['sequence'] ?? null)
            || !\is_array($data['asset_mirrors'] ?? null)
            || !array_is_list($data['asset_mirrors'])
            || !\is_array($data['plugins'] ?? null)
            || !array_is_list($data['plugins'])
        ) {
            throw new InvalidMarketSnapshotContentException('Market snapshot is missing one of the required fields.');
        }

        return new self(
            $data['core_version'],
            $data['sequence'],
            array_map(strval(...), $data['asset_mirrors']),
            array_map(MarketSnapshotPlugin::fromArray(...), $data['plugins']),
        );
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), \JSON_THROW_ON_ERROR);
    }
}
