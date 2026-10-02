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

/**
 * The three states a caller with no active {@see \AnimeDb\PluginContracts\Filler\FillerInterface}
 * plugin needs to tell apart to point the user at the right next step — see
 * {@see FillerRegistry::fillerAvailability()}.
 */
enum FillerAvailabilityState
{
    /** No installed plugin implements FillerInterface at all. */
    case NotInstalled;

    /**
     * At least one filler plugin is installed, but none is active, and none of the inactive
     * ones has its own settings page to link to (either disabled as a whole, or enabled but
     * with no settings page).
     */
    case DisabledNoSettingsPage;

    /**
     * A filler plugin is installed and enabled as a whole, only its `features.filler` toggle is
     * off, and it has its own settings page — the natural place to flip that toggle back on.
     */
    case DisabledWithSettingsPage;
}
