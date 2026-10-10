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

use App\Kernel;
use App\Service\Version\AppVersionResolver;
use Composer\InstalledVersions;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Container-level regression test for PR #563's review: a unit test constructing
 * {@see \App\Service\Plugin\InstalledPluginsRegistry}/{@see \App\Service\Plugin\ZipPluginInstaller}
 * directly and passing `pluginContractsVersion` by hand cannot catch a wiring bug where the actual
 * compiled container never delivers a real value to either of them. Only booting the real kernel
 * and reading the compiled `app.plugin_contracts_version` parameter exercises
 * {@see Kernel::build()} and {@see Kernel::configureContainer()} the same way
 * `bin/console`/FrankenPHP does.
 */
final class KernelTest extends KernelTestCase
{
    public function testPluginCacheDirLivesUnderTheRuntimeDirectory(): void
    {
        $previous = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $_SERVER['APP_RUNTIME_DIR'] = '/x';
        try {
            $container = new ContainerBuilder();
            (new Kernel('test', true))->build($container);
        } finally {
            $this->restoreRuntimeDir($previous);
        }

        $this->assertSame('/x/plugin-cache', $container->getParameter('app.plugin_cache_dir'));
    }

    public function testPluginCacheDirFallsBackToVarWhenTheRuntimeDirectoryIsEmpty(): void
    {
        $previous = $_SERVER['APP_RUNTIME_DIR'] ?? null;
        $_SERVER['APP_RUNTIME_DIR'] = '';
        try {
            $kernel = new Kernel('test', true);
            $container = new ContainerBuilder();
            $kernel->build($container);
        } finally {
            $this->restoreRuntimeDir($previous);
        }

        $this->assertSame($kernel->getProjectDir().'/var/plugin-cache', $container->getParameter('app.plugin_cache_dir'));
    }

    private function restoreRuntimeDir(?string $previous): void
    {
        if ($previous === null) {
            unset($_SERVER['APP_RUNTIME_DIR']);
        } else {
            $_SERVER['APP_RUNTIME_DIR'] = $previous;
        }
    }

    public function testPluginContractsVersionParameterIsSetFromInstalledVersions(): void
    {
        self::bootKernel();

        $version = self::getContainer()->getParameter('app.plugin_contracts_version');

        $this->assertSame(InstalledVersions::getPrettyVersion('anime-db/plugin-contracts'), $version);
        $this->assertNotNull($version);
    }

    /**
     * Same rationale as the plugin-contracts test above, for issue #565: a unit test constructing
     * {@see \App\Service\Plugin\InstalledPluginsRegistry} directly and passing `coreVersion` by
     * hand cannot catch a wiring bug where the compiled container never delivers a real value to
     * it — this is exactly the failure PR #563 hit for `pluginContractsVersion`. Only booting the
     * real kernel and reading the compiled `app.core_version` parameter exercises
     * {@see Kernel::build()}/{@see Kernel::coreVersion()} the same way `bin/console`/
     * FrankenPHP does. Asserted against {@see AppVersionResolver} directly, the same source
     * `coreVersion()` reads from when CORE_VERSION is unset — true in this test process, so this
     * also pins that the parameter is not null in a unit test, one of the three modes issue #565
     * requires a real version in.
     */
    public function testCoreVersionParameterIsSetFromPackageJson(): void
    {
        self::bootKernel();

        $version = self::getContainer()->getParameter('app.core_version');
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        if (!\is_string($projectDir)) {
            throw new \RuntimeException('kernel.project_dir is expected to be a string.');
        }

        $this->assertSame(
            AppVersionResolver::resolve($projectDir, new NullLogger()),
            $version,
        );
        $this->assertNotNull($version);
    }
}
