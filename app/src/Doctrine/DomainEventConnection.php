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
use Doctrine\DBAL\Connection;

/**
 * The DBAL connection (`wrapper_class` of the `default` connection only) that tells the
 * {@see DomainEventPublisher} when the data really is final: the outermost commit() releases the
 * events its flushes produced, while a rollBack() or a failed commit() drops only the events of its
 * own nesting level, so a rolled back savepoint takes just its events with it.
 *
 * DBAL creates the wrapper with `new`, so the publisher arrives through a setter call on the
 * service definition (see DomainEventConnectionPass), not through a static holder.
 */
final class DomainEventConnection extends Connection
{
    private ?DomainEventPublisher $publisher = null;

    public function setDomainEventPublisher(DomainEventPublisher $publisher): void
    {
        $this->publisher = $publisher;
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();

        $this->publisher?->enterLevel();
    }

    public function commit(): void
    {
        $levelBefore = $this->getTransactionNestingLevel();

        try {
            parent::commit();
        } catch (\Throwable $exception) {
            // DBAL lowers the nesting level even when the commit itself failed, so the caller will
            // not rollBack() that level: drop its events here. A commit refused up front (rollback
            // only) leaves the level open and the caller's rollBack() drops it.
            if ($this->getTransactionNestingLevel() < $levelBefore) {
                $this->dropLevel();
            }

            throw $exception;
        }

        $this->publisher?->commitLevel();

        if ($this->getTransactionNestingLevel() === 0) {
            $this->publisher?->dispatchReady();
        }
    }

    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->dropLevel();
        }
    }

    private function dropLevel(): void
    {
        if ($this->getTransactionNestingLevel() === 0) {
            $this->publisher?->discard();
        } else {
            $this->publisher?->rollBackLevel();
        }
    }
}
