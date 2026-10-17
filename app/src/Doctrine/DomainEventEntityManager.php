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

namespace App\Doctrine;

use App\DomainEvent\DomainEventPublisher;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hands out the ready domain events once flush() has returned (UnitOfWork cleaned up) and no
 * transaction is open. Inside an outer transaction the events wait for
 * {@see DomainEventConnection::commit()}.
 */
final class DomainEventEntityManager extends EntityManagerDecorator
{
    public function __construct(
        EntityManagerInterface $wrapped,
        // Not readonly: ManagerRegistry::resetManager() re-runs the constructor on the same instance.
        private DomainEventPublisher $publisher,
    ) {
        parent::__construct($wrapped);
    }

    public function flush(): void
    {
        parent::flush();

        if (!$this->getConnection()->isTransactionActive()) {
            $this->publisher->dispatchReady();
        }
    }
}
