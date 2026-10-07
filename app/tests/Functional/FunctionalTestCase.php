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

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;

/**
 * Base for HTTP-level tests: requests go through the real kernel, routing, CSRF and session.
 * The database is the dedicated `var/test/data.db` from `.env.test` (never the developer's
 * `data/data.db`); its schema is rebuilt before every test so tests do not see each other's rows.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        // Start from fresh files: SchemaTool::dropSchema() swallows errors, so a drop that fails
        // leaves the old tables behind and createSchema() then dies with "table already exists".
        $databaseDir = self::projectDir().'/var/test';
        if (!is_dir($databaseDir)) {
            mkdir($databaseDir, 0o777, true);
        }
        foreach (['data.db', 'queue.db'] as $file) {
            foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
                @unlink($databaseDir.'/'.$file.$suffix);
            }
        }

        $this->client = self::createClient();

        // config.json holds the locale/theme a test may have switched; drop it so tests do not
        // inherit each other's settings. The path is resolved by the container (it is relative
        // to the CWD), exactly as the application sees it.
        $configPath = self::getContainer()->getParameter('app.config_path');
        if (\is_string($configPath)) {
            @unlink($configPath);
        }

        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // Entity listeners dispatch to the Doctrine-backed `async` transport (var/test/queue.db);
        // its table is not part of the ORM schema.
        foreach (['async', 'media', 'plugins', 'sync'] as $name) {
            $transport = self::getContainer()->get('messenger.transport.'.$name);
            if ($transport instanceof SetupableTransportInterface) {
                $transport->setup();
            }
        }
    }

    protected function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    protected static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }
}
