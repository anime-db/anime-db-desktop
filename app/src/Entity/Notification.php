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

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One entry of the local notification history (issue #1023). It is a record of an event, not the
 * state of another entity: it stays when the state it reported is gone.
 *
 * `kind`, `severity` and `subjectType` are plain strings on purpose — an enum-typed column would
 * fail hydration of the whole list on a value this code no longer knows. The text is never stored:
 * only `kind` + `params` (a snapshot of JSON scalars), the text is rendered on display.
 * Records are written through {@see \App\Service\Notification\NotificationCenter}, one atomic SQL
 * statement per record; the entity is for reading and for marking a record read.
 *
 * Merge key: the unique index on `dedup_key`, partial in the schema (WHERE `read_at` IS NULL, see the
 * migration): DBAL cannot express a SQLite partial index in the mapping, so the mapping declares
 * only its columns and the predicate lives in the migration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'IDX_NOTIFICATION_OCCURRED_AT', columns: ['occurred_at'])]
#[ORM\UniqueConstraint(name: 'UNIQ_NOTIFICATION_DEDUP_UNREAD', columns: ['dedup_key'])]
class Notification
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 64)]
    public readonly string $kind;

    #[ORM\Column(length: 16)]
    public private(set) string $severity;

    /** @var array<string, string|int|bool|null> */
    #[ORM\Column(type: 'json')]
    public private(set) array $params;

    #[ORM\Column(name: 'subject_type', length: 32, nullable: true)]
    public readonly ?string $subjectType;

    #[ORM\Column(name: 'subject_id', length: 64, nullable: true)]
    public readonly ?string $subjectId;

    #[ORM\Column(name: 'occurred_at', type: 'unix_timestamp')]
    public private(set) \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'read_at', type: 'unix_timestamp', nullable: true)]
    public private(set) ?\DateTimeImmutable $readAt = null;

    #[ORM\Column]
    public private(set) int $occurrences = 1;

    #[ORM\Column(name: 'dedup_key', length: 255, nullable: true)]
    public readonly ?string $dedupKey;

    /** @param array<string, string|int|bool|null> $params */
    public function __construct(
        string $kind,
        string $severity,
        array $params,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $dedupKey = null,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->kind = $kind;
        $this->severity = $severity;
        $this->params = $params;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->dedupKey = $dedupKey;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markRead(): void
    {
        $this->readAt ??= new \DateTimeImmutable();
    }
}
