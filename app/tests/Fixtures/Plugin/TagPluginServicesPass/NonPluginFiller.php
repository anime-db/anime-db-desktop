<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\TagPluginServicesPass;

use AnimeDb\PluginContracts\FillerInterface;
use AnimeDb\PluginContracts\PluginAnimeData;

/**
 * Fixture used by TagPluginServicesPassTest: implements {@see FillerInterface} but lives outside
 * any plugin's namespace (under the host's own `App\` namespace), so the pass under test must
 * never tag it, no matter which plugins are installed.
 */
final class NonPluginFiller implements FillerInterface
{
    public function resolveExternalId(array $urls): ?string
    {
        return null;
    }

    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        return [];
    }

    public function findById(string $externalId): ?PluginAnimeData
    {
        return null;
    }

    public function getFillableFields(): array
    {
        return [];
    }
}
