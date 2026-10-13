<?php

declare(strict_types=1);

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

namespace App\Service\Plugin\Filler;

use App\Entity\Anime;
use App\Service\Plugin\FillerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Whether the "fill from links" action (issue #1049) has anything to work with: at least one
 * active filler recognises an id in the record's links. Calls {@see
 * \AnimeDb\PluginContracts\ExternalIdResolutionInterface::resolveExternalId()} directly — a pure URL parse with no network,
 * no database access and no {@see \App\Service\Plugin\ExternalIdResolver::claim()}.
 */
final class SaturationAvailability
{
    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isAvailable(Anime $anime): bool
    {
        $urls = array_map(static fn ($source): string => $source->url, $anime->getSources()->toArray());
        if ($urls === []) {
            return false;
        }

        foreach ($this->fillerRegistry->findAllActive() as $pluginId => $filler) {
            try {
                if ($filler->resolveExternalId($urls) !== null) {
                    return true;
                }
            } catch (\Throwable $exception) {
                // Plugin code: one broken filler must not take the record card down with it.
                $this->logger->warning('Filler "{plugin}" failed to resolve an external id for the saturation check.', [
                    'plugin' => (string) $pluginId,
                    'exception' => $exception,
                ]);
            }
        }

        return false;
    }
}
