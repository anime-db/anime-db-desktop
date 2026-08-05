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

namespace AnimeDb\Plugins\FakeVendor;

/**
 * Fixture used by OwnManifestScopePassTest for a plugin service whose constructor does not
 * type-hint {@see \AnimeDb\PluginContracts\Manifest\OwnManifestInterface}: the pass under test
 * must not add a binding to it, since `ResolveBindingsPass` hard-fails on a binding with no
 * matching constructor argument.
 */
final class FakeNonConsumer
{
    public function __construct()
    {
    }
}
