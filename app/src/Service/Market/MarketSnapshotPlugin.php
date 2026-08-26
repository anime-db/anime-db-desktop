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
 * One plugin's entry inside a {@see MarketSnapshot}, built by {@see MarketSnapshotBuilder} from a
 * {@see MarketPlugin} plus the core version the snapshot is built for.
 *
 * `resolvedVersion`/`sha256` are `null` when {@see MarketPlugin::resolveCompatibleVersion()} found
 * no version compatible with the target core — the plugin is still included (not dropped), so the
 * storefront can render it inactive with a "needs core version X" hint built from `latestVersion`/
 * `latestVersionCore`.
 *
 * `translationKeyCount` (issue #514) is the resolved version's own key count, carried over
 * verbatim from {@see MarketPluginVersion::$translationKeyCount} — never a ready-made percentage,
 * which the storefront computes at render time against the app's own key count instead, so the
 * figure never goes stale between an app upgrade and the next market refresh. Treated as
 * *optional* in {@see fromArray()}, unlike every other field here: a snapshot cached before this
 * field existed must keep loading, just without a coverage badge for that plugin.
 */
final class MarketSnapshotPlugin
{
    /**
     * @param array<string, mixed> $manifest raw manifest fields, shaped like `manifest.json`, for
     *                                       display only — never re-parsed back into a
     *                                       {@see \AnimeDb\PluginContracts\Manifest\Manifest}
     */
    public function __construct(
        public readonly string $id,
        public readonly array $manifest,
        public readonly ?string $resolvedVersion,
        public readonly ?string $sha256,
        public readonly string $latestVersion,
        public readonly string $latestVersionCore,
        public readonly ?int $translationKeyCount = null,
    ) {
    }

    /**
     * @return array{id: string, manifest: array<string, mixed>, resolvedVersion: ?string, sha256: ?string, latestVersion: string, latestVersionCore: string, translationKeyCount: ?int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'manifest' => $this->manifest,
            'resolvedVersion' => $this->resolvedVersion,
            'sha256' => $this->sha256,
            'latestVersion' => $this->latestVersion,
            'latestVersionCore' => $this->latestVersionCore,
            'translationKeyCount' => $this->translationKeyCount,
        ];
    }

    /**
     * @throws InvalidMarketSnapshotContentException if $data is missing one of the fields a
     *                                               snapshot plugin entry requires
     */
    public static function fromArray(mixed $data): self
    {
        if (!\is_array($data)
            || !\is_string($data['id'] ?? null)
            || !\is_array($data['manifest'] ?? null)
            || !\is_string($data['latestVersion'] ?? null)
            || !\is_string($data['latestVersionCore'] ?? null)
            || !(\is_string($data['resolvedVersion'] ?? null) || ($data['resolvedVersion'] ?? null) === null)
            || !(\is_string($data['sha256'] ?? null) || ($data['sha256'] ?? null) === null)
            || !(\is_int($data['translationKeyCount'] ?? null) || ($data['translationKeyCount'] ?? null) === null)
        ) {
            throw new InvalidMarketSnapshotContentException('Market snapshot plugin entry is malformed.');
        }

        return new self(
            $data['id'],
            $data['manifest'],
            $data['resolvedVersion'],
            $data['sha256'],
            $data['latestVersion'],
            $data['latestVersionCore'],
            $data['translationKeyCount'] ?? null,
        );
    }
}
