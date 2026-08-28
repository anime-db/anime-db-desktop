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

use AnimeDb\PluginContracts\Manifest\InvalidManifestException;
use AnimeDb\PluginContracts\Manifest\InvalidManifestJsonException;
use AnimeDb\PluginContracts\Manifest\ManifestParser;
use AnimeDb\PluginContracts\Manifest\ManifestValidationError;
use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\Market\Exception\InvalidPluginRegistryContentException;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Psr\Log\LoggerInterface;

/**
 * A parsed, already signature-verified `plugins-registry.json` (see
 * {@see PluginRegistryLoader}). Extracts `sequence` (anti-rollback), `asset_mirrors` (URL
 * templates for asset downloads), per plugin the `sha256` of each published version, and the
 * plugin catalog itself ({@see plugins()}) the market storefront (issue #220) renders and
 * resolves an installable version from.
 */
final class PluginRegistry
{
    /**
     * @param list<string>                         $assetMirrors              URL templates containing
     *                                                                        the `<id>`/`<version>`/`<file>` macros
     * @param array<string, array<string, string>> $sha256ByVersionByPluginId pluginId => [version => sha256]
     * @param list<MarketPlugin>                   $plugins
     */
    private function __construct(
        public readonly int $sequence,
        public readonly array $assetMirrors,
        private readonly array $sha256ByVersionByPluginId,
        private readonly array $plugins,
    ) {
    }

    /**
     * @throws InvalidPluginRegistryContentException if $json is not valid JSON, or is missing
     *                                               one of the fields the registry format requires
     */
    public static function fromJson(string $json, LoggerInterface $logger): self
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

        return new self(
            $sequence,
            array_map(strval(...), $assetMirrors),
            self::extractSha256Map($plugins),
            self::extractPlugins($plugins, $logger),
        );
    }

    public function findVersionSha256(PluginId $pluginId, string $version): ?string
    {
        return $this->sha256ByVersionByPluginId[(string) $pluginId][$version] ?? null;
    }

    /**
     * @return list<MarketPlugin>
     */
    public function plugins(): array
    {
        return $this->plugins;
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

    /**
     * Builds the storefront catalog (issue #220): one {@see MarketPlugin} per registry entry
     * that has a well-formed `id`, a `manifest` object {@see ManifestParser::parse()} accepts,
     * and at least one well-formed `versions[]` entry. A malformed entry is skipped rather than
     * failing the whole registry — the same leniency {@see extractSha256Map()} already applies,
     * since one bad plugin entry (a publishing bug on the registry side) should not take down the
     * storefront for every other plugin.
     *
     * @param list<mixed> $plugins
     *
     * @return list<MarketPlugin>
     */
    private static function extractPlugins(array $plugins, LoggerInterface $logger): array
    {
        $result = [];

        foreach ($plugins as $plugin) {
            if (!\is_array($plugin) || !\is_string($plugin['id'] ?? null) || !\is_array($plugin['manifest'] ?? null) || !\is_array($plugin['versions'] ?? null)) {
                $logger->warning('Skipping market plugin registry entry with a missing or malformed id, manifest, or versions block.', [
                    'pluginId' => \is_array($plugin) && \is_string($plugin['id'] ?? null) ? $plugin['id'] : null,
                ]);

                continue;
            }

            $versionsByNumber = [];
            foreach ($plugin['versions'] as $version) {
                if (!\is_array($version) || !\is_string($version['version'] ?? null) || !\is_string($version['core'] ?? null)) {
                    $logger->warning('Skipping market plugin version entry with a missing or malformed version or core constraint.', [
                        'pluginId' => $plugin['id'],
                    ]);

                    continue;
                }

                if (!self::isValidVersion($version['version']) || !self::isValidConstraint($version['core'])) {
                    $logger->warning('Skipping market plugin version entry with an unparseable version or core constraint.', [
                        'pluginId' => $plugin['id'],
                        'version' => $version['version'],
                        'core' => $version['core'],
                    ]);

                    continue;
                }

                $translationKeyCount = \is_int($version['translation_keys_count'] ?? null) ? $version['translation_keys_count'] : null;
                $locales = self::extractVersionLocales($version['locales'] ?? null);
                $versionsByNumber[$version['version']] = new MarketPluginVersion($version['version'], $version['core'], $translationKeyCount, $locales);
            }

            if ($versionsByNumber === []) {
                $logger->warning('Skipping market plugin with no valid version entries.', [
                    'pluginId' => $plugin['id'],
                ]);

                continue;
            }

            try {
                $id = new PluginId($plugin['id']);
            } catch (InvalidPluginIdException $exception) {
                $logger->warning('Skipping market plugin with an invalid id.', [
                    'pluginId' => $plugin['id'],
                    'exception' => $exception,
                ]);

                continue;
            }

            try {
                $manifest = (new ManifestParser())->parse((string) json_encode($plugin['manifest'], \JSON_THROW_ON_ERROR));
            } catch (InvalidManifestException $exception) {
                $logger->error('Skipping market plugin with an invalid manifest.', [
                    'pluginId' => (string) $id,
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
            } catch (InvalidManifestJsonException|\JsonException $exception) {
                $logger->warning('Skipping market plugin with a manifest that is not valid JSON.', [
                    'pluginId' => (string) $id,
                    'exception' => $exception,
                ]);

                continue;
            }

            $sortedVersionNumbers = Semver::rsort(array_keys($versionsByNumber));

            $result[] = new MarketPlugin($id, $manifest, array_map(
                static fn (string $versionNumber): MarketPluginVersion => $versionsByNumber[$versionNumber],
                $sortedVersionNumbers,
            ));
        }

        return $result;
    }

    /**
     * `Semver::rsort()` ({@see extractPlugins()}) and `Semver::satisfies()`
     * ({@see MarketPlugin::resolveCompatibleVersion()}) both throw `UnexpectedValueException` on
     * a version string `composer/semver` cannot normalize (e.g. `"latest"`). Validating here, and
     * skipping the version entry when it fails, keeps a single malformed publish from taking down
     * the whole registry or the storefront page.
     */
    private static function isValidVersion(string $version): bool
    {
        try {
            (new VersionParser())->normalize($version);

            return true;
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * Same rationale as {@see isValidVersion()}, but for the `core` constraint string that later
     * flows into `Semver::satisfies()` (e.g. an empty/malformed constraint like `"~"`).
     */
    private static function isValidConstraint(string $constraint): bool
    {
        try {
            (new VersionParser())->parseConstraints($constraint);

            return true;
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * Issue #543: a version entry's `locales` is optional and, unlike `translation_keys_count`,
     * a list rather than a scalar — anything other than a well-formed list of strings (missing,
     * not a list, or holding a non-string element) is treated as "not published", not as an
     * empty list, so the storefront can tell "languages unknown" apart from "this version ships
     * no languages at all".
     *
     * @return list<string>|null
     */
    private static function extractVersionLocales(mixed $locales): ?array
    {
        if (!\is_array($locales) || !array_is_list($locales)) {
            return null;
        }

        foreach ($locales as $locale) {
            if (!\is_string($locale)) {
                return null;
            }
        }

        return $locales;
    }
}
