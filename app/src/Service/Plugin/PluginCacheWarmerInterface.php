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

use App\Service\Plugin\Exception\PluginCacheWarmupException;

/**
 * Seam between {@see ZipPluginInstaller} (and any other plugin-activation caller) and the
 * isolated-process cache warm-up, so a caller can be unit-tested against a fake warm-up outcome
 * instead of the real subprocess {@see PluginCacheWarmer} spawns — in particular the rollback
 * path a failed warm-up triggers.
 */
interface PluginCacheWarmerInterface
{
    /**
     * @throws PluginCacheWarmupException
     */
    public function warmUp(): void;
}
