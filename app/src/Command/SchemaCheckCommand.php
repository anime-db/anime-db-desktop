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

use App\Service\Plugin\PhpCliCommand;
use App\Service\Schema\SchemaSnapshot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Applies every migration to an empty database created in a throwaway directory, snapshots
 * `sqlite_master` and compares it with the committed `migrations/schema.golden.tsv`. With
 * `--write` the golden file is overwritten instead. The schema shape is checked, not the data.
 *
 * The database is always fresh: on an already migrated one `doctrine:migrations:migrate` is a
 * no-op and the comparison would prove nothing. `DATABASE_URL` is always overridden for the same
 * reason — the `.env` default must never be used.
 */
#[AsCommand(name: 'app:schema:check', description: 'Compare the schema built by all migrations with the committed golden snapshot')]
final class SchemaCheckCommand extends Command
{
    public const GOLDEN_FILE = 'migrations/schema.golden.tsv';

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Overwrite the golden snapshot instead of comparing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $goldenPath = $this->projectDir.'/'.self::GOLDEN_FILE;

        $filesystem = new Filesystem();
        $workDir = sys_get_temp_dir().'/animedb_schema_check_'.bin2hex(random_bytes(6));
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
            $migrate->setTimeout(300);
            $migrate->run();
            if (!$migrate->isSuccessful()) {
                $io->error('Migrations failed:');
                $io->writeln($migrate->getOutput().$migrate->getErrorOutput());

                return $migrate->getExitCode() ?: Command::FAILURE;
            }

            $actual = SchemaSnapshot::fromMasterRows($this->readMaster($dbPath));
        } finally {
            $filesystem->remove($workDir);
        }

        if ($input->getOption('write')) {
            $filesystem->dumpFile($goldenPath, SchemaSnapshot::serialize($actual));
            $io->success(\sprintf('Golden snapshot written to %s (%d rows)', self::GOLDEN_FILE, \count($actual)));

            return Command::SUCCESS;
        }

        if (!is_file($goldenPath)) {
            $io->error(\sprintf('Golden snapshot %s not found; run with --write to create it', self::GOLDEN_FILE));

            return Command::FAILURE;
        }

        $diff = SchemaSnapshot::diff(SchemaSnapshot::parse((string) file_get_contents($goldenPath)), $actual);
        if ($diff !== []) {
            $io->error(\sprintf('Schema differs from %s (< golden, > migrations); if intended, run with --write', self::GOLDEN_FILE));
            $io->writeln($diff);

            return Command::FAILURE;
        }

        $io->success('Schema matches the golden snapshot');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{type: string, name: string, sql: string|null}>
     */
    private function readMaster(string $dbPath): array
    {
        $pdo = new \PDO('sqlite:'.$dbPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $rows = [];
        $statement = $pdo->query('SELECT type, name, sql FROM sqlite_master');
        \assert($statement instanceof \PDOStatement);
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = ['type' => (string) $row['type'], 'name' => (string) $row['name'], 'sql' => $row['sql'] === null ? null : (string) $row['sql']];
        }
        unset($pdo);

        return $rows;
    }
}
