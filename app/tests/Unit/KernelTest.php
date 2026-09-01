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

namespace App\Tests\Unit;

use Composer\InstalledVersions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Container-level regression test for PR #563's review: a unit test constructing
 * {@see \App\Service\Plugin\InstalledPluginsRegistry}/{@see \App\Service\Plugin\ZipPluginInstaller}
 * directly and passing `pluginContractsVersion` by hand cannot catch a wiring bug where the actual
 * compiled container never delivers a real value to either of them. Only booting the real kernel
 * and reading the compiled `app.plugin_contracts_version` parameter exercises
 * {@see \App\Kernel::build()} and {@see \App\Kernel::configureContainer()} the same way
 * `bin/console`/FrankenPHP does.
 */
final class KernelTest extends KernelTestCase
{
    public function testPluginContractsVersionParameterIsSetFromInstalledVersions(): void
    {
        self::bootKernel();

        $version = self::getContainer()->getParameter('app.plugin_contracts_version');

        $this->assertSame(InstalledVersions::getPrettyVersion('anime-db/plugin-contracts'), $version);
        $this->assertNotNull($version);
    }
}
