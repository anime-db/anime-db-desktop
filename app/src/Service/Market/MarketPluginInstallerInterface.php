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

/**
 * Install/update entry point of {@see MarketPluginInstaller}, split out so callers that only need
 * to drive an installation (the market controller, the installer-selection command) do not depend
 * on the concrete download + unpack pipeline.
 */
interface MarketPluginInstallerInterface
{
    /**
     * @param bool $publishWorkersReload whether the live-reload event is published after success;
     *                                   pass `false` when the caller activates the plugin itself
     */
    public function installOrUpdate(PluginId $id, bool $update = false, bool $publishWorkersReload = true): PluginId;
}
