<?php

/**
 * AnimeDb package.
 *
 * @author    Peter Gribanov <info@peter-gribanov.ru>
 * @copyright Copyright (c) 2026, Peter Gribanov
 * @license   https://gnu.org GPL-3.0-or-later
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
 * along with this program. If not, see <https://gnu.org>.
 */

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\SyncReviewItemKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry in the persistent "needs review" list a sync run can raise (issue #267), so it
 * survives past the run itself until a user resolves it. $payload's shape depends on $kind: for
 * PotentialDuplicate it holds the clustered Anime ids (issue #216); a future
 * DeletedFromSource/DeletionConflict kind (issue #217) will carry affected source info instead.
 * Detecting duplicates and surfacing this list in the UI are out of scope here — this is storage only.
 */
#[ORM\Entity]
class SyncReviewItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 32, enumType: SyncReviewItemKind::class)]
    public readonly SyncReviewItemKind $kind;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public readonly array $payload;

    #[ORM\Column(type: 'unix_timestamp')]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'unix_timestamp', nullable: true)]
    public private(set) ?\DateTimeImmutable $resolvedAt = null;

    /** @param array<string, mixed> $payload */
    public function __construct(SyncReviewItemKind $kind, array $payload)
    {
        $this->kind = $kind;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }

    public function resolve(): void
    {
        $this->resolvedAt = new \DateTimeImmutable();
    }
}
