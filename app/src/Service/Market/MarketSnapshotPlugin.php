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
 *
 * `locales` (issue #543) is the resolved version's own locale list, carried over verbatim from
 * {@see MarketPluginVersion::$locales} — `null` means "not published for this version", not "no
 * languages", the same optional-field treatment as `translationKeyCount` in {@see fromArray()}.
 *
 * `pluginContracts` (issue #562) is the resolved version's own `plugin-contracts` constraint,
 * carried over verbatim from {@see MarketPluginVersion::$pluginContracts} the same way `locales`
 * is — `null` means "not published for this version". `incompatiblePluginContracts` is not
 * carried over from anywhere on {@see MarketPlugin}; it is computed by {@see MarketSnapshotBuilder}
 * from the *whole* `versions` list (via {@see MarketPlugin::hasCoreCompatibleVersion()}), which
 * this flattened, single-version DTO no longer has access to once built. It is `true` only when
 * `resolvedVersion` is `null` *and* that is specifically because every core-compatible version was
 * blocked by `plugin-contracts` — the storefront uses it to choose between the two "why can't I
 * install this" hints without redoing that determination itself. Defaults to `false` so a
 * snapshot cached before this field existed keeps loading with the pre-issue-#562 "needs core
 * version X" hint, same backward-compatibility treatment as `translationKeyCount`/`locales`.
 */
final class MarketSnapshotPlugin
{
    /**
     * @param array<string, mixed> $manifest raw manifest fields, shaped like `manifest.json`, for
     *                                       display only — never re-parsed back into a
     *                                       {@see \AnimeDb\PluginContracts\Manifest\Manifest}
     * @param list<string>|null    $locales
     */
    public function __construct(
        public readonly string $id,
        public readonly array $manifest,
        public readonly ?string $resolvedVersion,
        public readonly ?string $sha256,
        public readonly string $latestVersion,
        public readonly string $latestVersionCore,
        public readonly ?int $translationKeyCount = null,
        public readonly ?array $locales = null,
        public readonly ?string $pluginContracts = null,
        public readonly bool $incompatiblePluginContracts = false,
    ) {
    }

    /**
     * @return array{id: string, manifest: array<string, mixed>, resolvedVersion: ?string, sha256: ?string, latestVersion: string, latestVersionCore: string, translationKeyCount: ?int, locales: ?list<string>, pluginContracts: ?string, incompatiblePluginContracts: bool}
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
            'locales' => $this->locales,
            'pluginContracts' => $this->pluginContracts,
            'incompatiblePluginContracts' => $this->incompatiblePluginContracts,
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
            || !(self::isLocalesList($data['locales'] ?? null) || ($data['locales'] ?? null) === null)
            || !(\is_string($data['pluginContracts'] ?? null) || ($data['pluginContracts'] ?? null) === null)
            || !\is_bool($data['incompatiblePluginContracts'] ?? false)
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
            $data['locales'] ?? null,
            $data['pluginContracts'] ?? null,
            $data['incompatiblePluginContracts'] ?? false,
        );
    }

    private static function isLocalesList(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $locale) {
            if (!\is_string($locale)) {
                return false;
            }
        }

        return true;
    }
}
