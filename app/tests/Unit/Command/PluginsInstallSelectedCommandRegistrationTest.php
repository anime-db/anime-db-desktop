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

namespace App\Tests\Unit\Command;

use App\Command\PluginsInstallSelectedCommand;
use App\Controller\Settings\MarketController;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\LazyCommand;

/**
 * Pins the command name the native supervisor runs (the literal MUST match `COMMAND` in
 * `native/supervisor/plugins-install-selected.js`) and the shared selection-file path wiring: both
 * the command and the market controller take it from `INSTALLER_PLUGINS_PATH`, falling back to
 * the `dev_default` parameter, so the market page still builds without the variable.
 */
final class PluginsInstallSelectedCommandRegistrationTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        putenv('INSTALLER_PLUGINS_PATH');
        unset($_SERVER['INSTALLER_PLUGINS_PATH'], $_ENV['INSTALLER_PLUGINS_PATH']);

        parent::tearDown();
    }

    public function testSupervisorCommandNameIsRegisteredInTheApplication(): void
    {
        $command = (new Application(self::bootKernel()))->find('app:plugins:install-selected');
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }

        $this->assertInstanceOf(PluginsInstallSelectedCommand::class, $command);
    }

    public function testPathFallsBackToTheDevDefaultWithoutTheEnvVariableAndMarketControllerStillBuilds(): void
    {
        $container = self::getContainer();

        $this->assertSame(
            $container->getParameter('app.installer_plugins_path.dev_default'),
            $container->getParameter('app.installer_plugins_path'),
        );
        $this->assertInstanceOf(MarketController::class, $container->get(MarketController::class));
    }

    public function testPathComesFromTheEnvVariableWhenSet(): void
    {
        putenv('INSTALLER_PLUGINS_PATH=/data/installer-plugins.json');
        $_SERVER['INSTALLER_PLUGINS_PATH'] = $_ENV['INSTALLER_PLUGINS_PATH'] = '/data/installer-plugins.json';

        self::bootKernel();

        $this->assertSame('/data/installer-plugins.json', self::getContainer()->getParameter('app.installer_plugins_path'));
    }
}
