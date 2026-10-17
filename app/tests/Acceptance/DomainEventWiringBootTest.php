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

namespace App\Tests\Acceptance;

use App\Doctrine\DomainEventConnection;
use App\Doctrine\DomainEventEntityManager;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The domain-event wiring through the real container: the decorated EntityManager must survive
 * ManagerRegistry::resetManager() (callers rely on it after an EntityManager was closed), and
 * only the `default` connection may be the event-aware wrapper.
 */
final class DomainEventWiringBootTest extends KernelTestCase
{
    public function testResetManagerHandsBackAnOpenDecoratedManager(): void
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();

        $registry = $container->get('doctrine');
        self::assertInstanceOf(Registry::class, $registry);

        $manager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(DomainEventEntityManager::class, $manager);

        $manager->close();
        self::assertFalse($manager->isOpen());

        $registry->resetManager();

        $fresh = $registry->getManager();
        self::assertInstanceOf(DomainEventEntityManager::class, $fresh);
        self::assertTrue($fresh->isOpen());
        $forClass = $registry->getManagerForClass(\App\Entity\Anime::class);
        self::assertInstanceOf(EntityManagerInterface::class, $forClass);
        self::assertTrue($forClass->isOpen());
    }

    public function testOnlyTheDefaultConnectionIsEventAware(): void
    {
        self::bootKernel(['debug' => false]);
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(Registry::class, $registry);

        self::assertInstanceOf(DomainEventConnection::class, $registry->getConnection('default'));
        $queue = $registry->getConnection('queue');
        self::assertInstanceOf(Connection::class, $queue);
        self::assertNotInstanceOf(DomainEventConnection::class, $queue);
    }
}
