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

namespace AnimeDb\Plugins\FakeVendorTwo;

use AnimeDb\PluginContracts\Catalog\CatalogReaderInterface;

/**
 * Second-plugin counterpart of {@see \AnimeDb\Plugins\FakeVendor\FakeCatalogReaderConsumer},
 * living under a different plugin namespace ("fake-vendor-two") — used by
 * CatalogReaderScopePassTest to prove each plugin gets its own isolated instance.
 */
final class FakeCatalogReaderConsumerTwo
{
    public function __construct(
        public readonly CatalogReaderInterface $catalogReader,
    ) {
    }
}
