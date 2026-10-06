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
#[ORM\Index(name: 'IDX_SYNC_REVIEW_ITEM_RESOLVED_AT', fields: ['resolvedAt'])]
class SyncReviewItem
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 32, enumType: SyncReviewItemKind::class)]
    public readonly SyncReviewItemKind $kind;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public private(set) array $payload;

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

    /**
     * Reacts to the catalog entry $animeId having been deleted (issue #916). The references live
     * in a JSON payload without a foreign key, so nothing else would tidy them up. An item about
     * one entry (`anime_id`) is closed; a PotentialDuplicate (`anime_ids`) just loses the entry
     * and is closed only when fewer than two entries are left to compare.
     *
     * @return bool whether the item referenced the entry and was changed
     */
    public function forgetAnime(int $animeId): bool
    {
        if ($this->isResolved()) {
            return false;
        }

        if ($this->kind === SyncReviewItemKind::PotentialDuplicate) {
            /** @var list<int> $animeIds */
            $animeIds = $this->payload['anime_ids'] ?? [];
            if (!\in_array($animeId, $animeIds, true)) {
                return false;
            }

            $remaining = array_values(array_filter($animeIds, static fn (int $id): bool => $id !== $animeId));
            $this->payload = ['anime_ids' => $remaining] + $this->payload;
            if (\count($remaining) < 2) {
                $this->resolve();
            }

            return true;
        }

        if (($this->payload['anime_id'] ?? null) !== $animeId) {
            return false;
        }

        $this->resolve();

        return true;
    }
}
