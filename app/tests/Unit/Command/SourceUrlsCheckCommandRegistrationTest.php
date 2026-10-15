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

use App\Command\SourceUrlsCheckCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\LazyCommand;

/**
 * Pins the link between the command name the supervisor runs on every start and the command
 * registered in the application: `CommandTester` on a hand-built command bypasses
 * `#[AsCommand(name: …)]`, so a rename would otherwise surface only as "Command is not defined"
 * at startup. The literal below MUST match `COMMAND` in `native/supervisor/source-urls-check.js`
 * (and that file points back here).
 */
final class SourceUrlsCheckCommandRegistrationTest extends KernelTestCase
{
    public function testSupervisorCommandNameIsRegisteredInTheApplication(): void
    {
        $application = new Application(self::bootKernel());

        $command = $application->find('app:source-urls:check');
        // Lazy-registered commands are wrapped until first use.
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }

        $this->assertInstanceOf(SourceUrlsCheckCommand::class, $command);
    }
}
