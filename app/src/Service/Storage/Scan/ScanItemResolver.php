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

namespace App\Service\Storage\Scan;

use App\Repository\AnimeRepository;

/**
 * Whether a journal item is resolved is not stored: it is computed from the links records hold
 * right now — the pair (storage, storage_path) — so every way of making or breaking such a link
 * (scan, confirmation, "create entry", manual link, a download) closes the item with no extra
 * bookkeeping. Never touches the filesystem.
 *
 * - NeedsConfirmation, NeedsManualEntry, Conflict: resolved once some record holds the pair;
 * - FilesMissing: resolved once the record no longer holds it;
 * - anything else (Error, AutoLinked, ...) is history with no state: null.
 *
 * Names compare ignoring case and a trailing `\` or `/` (v1 imports store "Foo\").
 */
final class ScanItemResolver
{
    private const array NEEDS_DECISION = ['NeedsConfirmation', 'NeedsManualEntry', 'Conflict', 'FilesMissing'];

    public function __construct(private readonly AnimeRepository $animes)
    {
    }

    /**
     * The same items with a `resolved` key: true, false, or null for a type that has no state.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public function annotate(int $storageId, array $items): array
    {
        $held = $this->heldPairs($storageId);

        return array_map(
            fn (array $item): array => $item + ['resolved' => $this->isResolved($item, $held)],
            $items,
        );
    }

    /** How many items of the run still need the user's decision. */
    public function needsDecisionCount(ScanRun $run): int
    {
        $items = array_values(array_filter(
            $run->items,
            static fn (array $item): bool => \in_array($item['type'] ?? null, self::NEEDS_DECISION, true),
        ));
        if ($items === []) {
            return 0;
        }

        $held = $this->heldPairs($run->storageId);

        return \count(array_filter($items, fn (array $item): bool => $this->isResolved($item, $held) === false));
    }

    /**
     * @param array<string, mixed>            $item
     * @param array<string, array<int, true>> $held normalized storage_path => ids of the records holding it
     */
    private function isResolved(array $item, array $held): ?bool
    {
        $type = $item['type'] ?? null;
        $path = \is_string($item['storage_path'] ?? null) ? self::pathKey($item['storage_path']) : null;

        if ($path === null) {
            return null;
        }

        if (\in_array($type, ['NeedsConfirmation', 'NeedsManualEntry', 'Conflict'], true)) {
            return isset($held[$path]);
        }

        if ($type === 'FilesMissing') {
            $animeId = \is_array($item['anime'] ?? null) ? ($item['anime']['id'] ?? null) : null;

            return \is_int($animeId) ? !isset($held[$path][$animeId]) : !isset($held[$path]);
        }

        return null;
    }

    /** @return array<string, array<int, true>> */
    private function heldPairs(int $storageId): array
    {
        $held = [];
        foreach ($this->animes->findStoragePathsByStorageId($storageId) as $animeId => $path) {
            $held[self::pathKey($path)][$animeId] = true;
        }

        return $held;
    }

    /** The comparison key of a folder name: case and a trailing separator do not matter. */
    public static function pathKey(string $path): string
    {
        return mb_strtolower(rtrim($path, '\\/'));
    }
}
