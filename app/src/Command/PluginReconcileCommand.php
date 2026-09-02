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

use App\Service\Plugin\InstalledPluginsRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the installed-plugins index (`installed-plugins.php`) from the plugin directories on
 * disk, re-validating every `manifest.json` against the currently installed
 * `anime-db/plugin-contracts` version — see {@see InstalledPluginsRegistry::reconcile()} for the
 * scan/parse/rewrite logic itself. The native supervisor runs this once after a build change (see
 * `native/supervisor/plugin-reconcile.js`), so an index entry written by an older, more permissive
 * version of the contract does not survive an upgrade as-is.
 */
#[AsCommand(name: 'app:plugin:reconcile', description: 'Rebuild the installed-plugins index from the plugin directories on disk')]
final class PluginReconcileCommand extends Command
{
    public function __construct(private readonly InstalledPluginsRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->registry->reconcile();

        $io->success(\sprintf('Reconciled %d installed plugin(s).', \count($this->registry->all())));

        return Command::SUCCESS;
    }
}
