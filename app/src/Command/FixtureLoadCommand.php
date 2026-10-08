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

use App\Entity\Enum\StorageType;
use App\Entity\Storage;
use App\Repository\AnimeRepository;
use App\Service\AppSettingsProvider;
use App\Service\Install\SampleAnimeSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fills an empty database with a deterministic data set: the catalog entries with covers and the
 * "Sample" label from {@see SampleAnimeSeeder}, one storage and the user settings in config.json.
 * Used by scripts/fixture (the isolated environment of the screenshot run and of tests), never at
 * application startup.
 *
 * Refuses a non-empty catalog so it cannot be pointed at real user data by mistake: the result
 * is only reproducible when it starts from an empty schema.
 */
#[AsCommand(name: 'app:fixture:load', description: 'Load the deterministic fixture data set into an empty database')]
final class FixtureLoadCommand extends Command
{
    private const string STORAGE_NAME = 'Fixture storage';
    private const string STORAGE_PATH = '/fixture/storage';
    /** Fixed instant of every creation/update stamp, so two loads give identical rows. */
    private const int FIXED_TIMESTAMP = 1_767_225_600; // 2026-01-01T00:00:00Z
    private const string LOCALE = 'en';

    public function __construct(
        private readonly SampleAnimeSeeder $seeder,
        private readonly AnimeRepository $animes,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettingsProvider $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->animes->hasAny()) {
            $output->writeln('<error>The catalog is not empty — the fixture loads into an empty database only.</error>');

            return Command::FAILURE;
        }

        $this->seeder->seed();

        $storage = new Storage(self::STORAGE_NAME, self::STORAGE_PATH, StorageType::Folder);
        $this->entityManager->persist($storage);
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE anime SET date_add = :ts, date_update = :ts',
            ['ts' => self::FIXED_TIMESTAMP],
        );

        $this->settings->setLocale(self::LOCALE);
        $this->settings->setPresetDownloadsStorageId((int) $storage->id);

        $output->writeln('Fixture loaded.');

        return Command::SUCCESS;
    }
}
