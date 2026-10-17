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

namespace App\DomainEvent;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands the publisher to the `default` DBAL connection, and to it alone: the `queue` connection
 * (Messenger) must never release or drop the core's events. Also makes the original `default`
 * EntityManager non-shared, see {@see \App\Doctrine\DomainEventEntityManager}.
 */
final class DomainEventConnectionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('doctrine.dbal.default_connection')) {
            return;
        }

        // Runs before the decoration is applied, so the original manager keeps this flag once it
        // becomes the decorator's `.inner`.
        if ($container->hasDefinition('doctrine.orm.default_entity_manager')) {
            $container->getDefinition('doctrine.orm.default_entity_manager')->setShared(false);
        }

        $container->getDefinition('doctrine.dbal.default_connection')
            ->addMethodCall('setDomainEventPublisher', [new Reference(DomainEventPublisher::class)]);
    }
}
