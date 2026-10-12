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

namespace App\Tests\Unit\Service\Notification;

use App\Service\Notification\Kind\NotificationKindInterface;
use App\Service\Notification\NotificationKindRegistry;
use App\Tests\Support\NotificationTestKit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\RegisterAutoconfigureAttributesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * An implementation of the kind interface is picked up by the registry through autoconfiguration
 * alone: no tag in services.yaml, no change to the registry.
 */
final class NotificationKindWiringTest extends TestCase
{
    public function testAutoconfiguredKindClassIsFoundByTheRegistry(): void
    {
        $container = new ContainerBuilder();
        // what the resource loader does for every interface found under src/ (see services.yaml)
        (new RegisterAutoconfigureAttributesPass())->processClass($container, new \ReflectionClass(NotificationKindInterface::class));
        $container->register(NotificationTestKit::class, NotificationTestKit::class)
            ->setAutoconfigured(true)
            ->setPublic(true);
        $container->register(NotificationKindRegistry::class, NotificationKindRegistry::class)
            ->setAutowired(true)
            ->setPublic(true);
        $container->compile();

        $registry = $container->get(NotificationKindRegistry::class);

        $this->assertInstanceOf(NotificationKindRegistry::class, $registry);
        $this->assertNotNull($registry->tryFrom(NotificationTestKit::KIND));
        $this->assertNull($registry->tryFrom('missing_kind'));
    }
}
