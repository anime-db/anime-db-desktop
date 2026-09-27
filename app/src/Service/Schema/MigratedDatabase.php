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

namespace App\Service\Schema;

use App\Service\Exception\MigrationsFailedException;
use App\Service\Plugin\PhpCliCommand;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Builds a throwaway database by running the migrations into a temporary directory, hands its path
 * to a callback and removes the directory afterwards.
 *
 * The database is always fresh: on an already migrated one `doctrine:migrations:migrate` is a no-op,
 * so anything compared against it would prove nothing. The default DATABASE_URL is never used
 * either — it points at `data/data.db`, which does not exist on a clean checkout, and a migrated file
 * there would break the PHPUnit step (QueueConnectionTest asserts the queue path ends with
 * queue.db).
 */
final class MigratedDatabase
{
    private const MIGRATE_TIMEOUT_SECONDS = 300;

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @template T
     *
     * @param non-empty-string    $namePrefix   directory name prefix, to tell callers apart in /tmp
     * @param callable(string): T $withDatabase receives the path of the migrated SQLite file
     *
     * @return T
     *
     * @throws MigrationsFailedException if the migrations do not run through
     */
    public function build(string $namePrefix, callable $withDatabase): mixed
    {
        $filesystem = new Filesystem();
        $workDir = sys_get_temp_dir().'/'.$namePrefix.bin2hex(random_bytes(6));
        $filesystem->mkdir($workDir);

        try {
            $dbPath = $workDir.'/schema.db';
            $migrate = new Process(
                PhpCliCommand::forScript(\PHP_BINARY, $this->projectDir.'/bin/console', 'doctrine:migrations:migrate', '--no-interaction'),
                $this->projectDir,
                [
                    'DATABASE_URL' => 'sqlite:///'.$dbPath,
                    // The parent's Dotenv marks DATABASE_URL as "loaded from .env"; inherited by
                    // the child, that marker lets the child's Dotenv overwrite our override.
                    'SYMFONY_DOTENV_VARS' => false,
                ],
            );
            $migrate->setTimeout(self::MIGRATE_TIMEOUT_SECONDS);
            $migrate->run();
            if (!$migrate->isSuccessful()) {
                throw new MigrationsFailedException($migrate->getOutput().$migrate->getErrorOutput(), $migrate->getExitCode() ?? 1);
            }

            return $withDatabase($dbPath);
        } finally {
            $filesystem->remove($workDir);
        }
    }
}
