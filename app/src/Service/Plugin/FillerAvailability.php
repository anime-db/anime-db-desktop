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

use App\Entity\ValueObject\PluginId;

/**
 * Result of {@see FillerRegistry::fillerAvailability()}: which of the three states a caller with
 * no active filler plugin is in, plus the plugin id to link to when that state is
 * {@see FillerAvailabilityState::DisabledWithSettingsPage}.
 */
final class FillerAvailability
{
    private function __construct(
        public readonly FillerAvailabilityState $state,
        public readonly ?PluginId $pluginId = null,
    ) {
    }

    public static function notInstalled(): self
    {
        return new self(FillerAvailabilityState::NotInstalled);
    }

    public static function disabledNoSettingsPage(): self
    {
        return new self(FillerAvailabilityState::DisabledNoSettingsPage);
    }

    public static function disabledWithSettingsPage(PluginId $pluginId): self
    {
        return new self(FillerAvailabilityState::DisabledWithSettingsPage, $pluginId);
    }
}
