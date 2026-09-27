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
use App\Service\Schema\MappingSchemaComparator;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Compares the entity mapping with the schema the migrations actually build, and fails on any real
 * divergence: a column whose type, length or nullability drifted, a column present on one side
 * only, an index or foreign key declared in one place and not the other.
 *
 * Complements `app:schema:check`, which compares the built schema against the committed
 * `sqlite_master` snapshot and knows nothing about the mapping: that gate stays green even if an
 * index declaration is deleted from an entity. The axes are different and both are needed.
 *
 * `doctrine:schema:validate` is deliberately not used — see MappingSchemaComparator for the SQLite
 * introspection defect that makes it permanently red here.
 *
 * The database is built from scratch by running the migrations in a throwaway directory: the
 * default DATABASE_URL points at `data/data.db`, which does not exist on a clean checkout, and a
 * migrated file there would break the PHPUnit step (QueueConnectionTest asserts the queue file path
 * ends with queue.db).
 */
#[AsCommand(name: 'app:schema:mapping-check', description: 'Compare the entity mapping with the schema built by all migrations')]
final class SchemaMappingCheckCommand extends Command
{
    /**
     * FTS5 virtual tables and their shadow tables are excluded from introspection: their columns
     * have no declared type, and DBAL's SQLite introspection crashes on such a column
     * ("Undefined array key 1" in SQLiteSchemaManager). They carry no mapping either way. The filter
     * is applied to this command's own connection only, never to the container's.
     */
    private const INTROSPECTION_EXCLUDED_PREFIX = 'anime_fts';

    public function __construct(
        private readonly string $projectDir,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filesystem = new Filesystem();
        $workDir = sys_get_temp_dir().'/animedb_mapping_check_'.bin2hex(random_bytes(6));
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

            $divergences = $this->compare($dbPath);
        } finally {
            $filesystem->remove($workDir);
        }

        if ($divergences !== []) {
            $io->error('The entity mapping does not match the schema built by the migrations:');
            $io->writeln(array_map(static fn (string $line): string => ' * '.$line, $divergences));

            return Command::FAILURE;
        }

        $io->success('The entity mapping matches the schema built by the migrations.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function compare(string $dbPath): array
    {
        $configuration = new DbalConfiguration();
        $configuration->setSchemaAssetsFilter(
            static fn (string $assetName): bool => !str_starts_with($assetName, self::INTROSPECTION_EXCLUDED_PREFIX),
        );

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath], $configuration);

        try {
            // A dedicated EntityManager over the throwaway database, reusing the project's ORM
            // configuration so the naming strategy, custom types and mappings stay identical.
            $entityManager = new EntityManager($connection, $this->entityManager->getConfiguration());
            $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
            $mappingSchema = (new SchemaTool($entityManager))->getSchemaFromMetadata($metadata);

            $schemaManager = $connection->createSchemaManager();

            return MappingSchemaComparator::divergences(
                $schemaManager->createComparator()->compareSchemas($schemaManager->introspectSchema(), $mappingSchema),
            );
        } finally {
            $connection->close();
        }
    }
}
