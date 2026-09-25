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

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/**
 * Pure rule "a plugin lags behind the app's plugin-contracts version `V`": no published version
 * of the plugin declares a `plugin_contracts` constraint that `V` satisfies (a version with no
 * constraint counts as satisfying). A plugin the registry parser dropped always lags.
 *
 * No I/O and no console concerns: takes already-parsed data, returns a list. Closed failure
 * policy, unlike the runtime's fail-open one: a `V` that is missing, unstable or unparseable
 * throws {@see PluginContractsCheckException} instead of yielding a verdict.
 */
final class PluginContractsLagDetector
{
    /**
     * @param list<PluginContractPins> $plugins
     *
     * @return list<LaggingPlugin>
     *
     * @throws PluginContractsCheckException
     */
    public function detect(?string $appContractsVersion, array $plugins): array
    {
        if ($appContractsVersion === null || $appContractsVersion === '') {
            throw new PluginContractsCheckException('The plugin-contracts version of the app is unknown.');
        }

        $parser = new VersionParser();
        try {
            $stability = VersionParser::parseStability($appContractsVersion);
            $parser->normalize($appContractsVersion);
        } catch (\UnexpectedValueException $exception) {
            throw new PluginContractsCheckException(\sprintf('The plugin-contracts version of the app "%s" cannot be parsed.', $appContractsVersion), previous: $exception);
        }

        if ($stability !== 'stable') {
            throw new PluginContractsCheckException(\sprintf('The plugin-contracts version of the app "%s" is not a stable release.', $appContractsVersion));
        }

        $lagging = [];
        foreach ($plugins as $plugin) {
            if (!$plugin->parsed) {
                $lagging[] = new LaggingPlugin($plugin->id, LagReason::MANIFEST_NOT_PARSEABLE, $plugin->latestVersion, $plugin->latestPin);

                continue;
            }

            if (!$this->acceptedByAnyVersion($appContractsVersion, $plugin->pins)) {
                $lagging[] = new LaggingPlugin($plugin->id, LagReason::NO_ACCEPTING_VERSION, $plugin->latestVersion, $plugin->latestPin);
            }
        }

        return $lagging;
    }

    /**
     * @param list<string|null> $pins
     */
    private function acceptedByAnyVersion(string $appContractsVersion, array $pins): bool
    {
        foreach ($pins as $pin) {
            try {
                if ($pin === null || Semver::satisfies($appContractsVersion, $pin)) {
                    return true;
                }
            } catch (\UnexpectedValueException $exception) {
                throw new PluginContractsCheckException(\sprintf('The plugin-contracts pin "%s" cannot be evaluated.', $pin), previous: $exception);
            }
        }

        return false;
    }
}
