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

namespace App\Command;

use App\Entity\ValueObject\PluginId;
use App\Service\Plugin\PluginRemover;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI-only rollback path (issue #411): the native supervisor runs this via `php-command.js` when
 * a live worker restart after a plugin install fails its healthcheck, to bring the app back to a
 * known-good, pre-plugin state instead of retrying against a broken one. Not exposed anywhere in
 * the settings UI — a normal plugin removal flow (issue #225) is a separate, not yet built,
 * feature.
 */
#[AsCommand(name: 'app:plugin:deactivate', description: 'Remove an installed plugin from disk and the installed-plugins index')]
final class PluginDeactivateCommand extends Command
{
    public function __construct(private readonly PluginRemover $remover)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pluginId', InputArgument::REQUIRED, 'Id of the plugin to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pluginId = new PluginId((string) $input->getArgument('pluginId'));
        $this->remover->remove($pluginId);

        $io->success(\sprintf('Removed plugin "%s".', $pluginId));

        return Command::SUCCESS;
    }
}
