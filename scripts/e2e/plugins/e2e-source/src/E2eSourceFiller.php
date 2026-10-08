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

namespace AnimeDb\Plugins\E2eSource;

use AnimeDb\PluginContracts\Filler\FillerInterface;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Search\SearchByPluginCandidate;
use AnimeDb\PluginContracts\Sync\SyncItem;
use AnimeDb\PluginContracts\Sync\SyncRemovalInterface;

/**
 * Offline filler for the E2E scenarios. What it answers is chosen by the `mode` file next to the
 * manifest, read on every call, so a scenario can switch it while the server keeps running:
 *
 *  - `empty`  (default) — nothing is found, the host shows its "no match" notice;
 *  - `images` — one record whose gallery holds a single frame (the file is placed in the media
 *               directory beforehand, so the host never has to download it);
 *  - `linkable` — one record with no media, enough to link an existing entry to this source.
 *
 * It is also a sync source whose list can drop an entry ({@see SyncRemovalInterface}); the list is
 * empty and nothing leaves the process. Every remove() call appends its external id to the
 * `removed` file next to the manifest, so a scenario can tell whether the removal reached the plugin.
 */
final class E2eSourceFiller implements SyncRemovalInterface
{
    public const string LINKABLE_ID = 'e2e-linkable';
    public const string FRAME_URL = 'https://frames.invalid/e2e-frame.webp';

    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        return match ($this->mode()) {
            'images' => [new SearchByPluginCandidate('e2e-source', $name, 'e2e-1')],
            'linkable' => [new SearchByPluginCandidate('e2e-source', $name, self::LINKABLE_ID)],
            default => [],
        };
    }

    public function findById(string $externalId): ?PluginAnimeData
    {
        // The host caches what findById() returns by (plugin, external id) outside the environment
        // directory, so every mode answers under an id of its own.
        return match ([$this->mode(), $externalId]) {
            ['images', 'e2e-1'] => new PluginAnimeData(title: 'E2E', images: [self::FRAME_URL]),
            ['linkable', self::LINKABLE_ID] => new PluginAnimeData(title: 'E2E'),
            default => null,
        };
    }

    public function resolveExternalId(array $urls): ?string
    {
        return null;
    }

    public function getFillableFields(): array
    {
        return ['cover', 'images'];
    }

    public function push(SyncItem $item): SyncItem
    {
        return $item;
    }

    public function pull(): iterable
    {
        return [];
    }

    public function remove(string $externalId): void
    {
        file_put_contents(\dirname(__DIR__).'/removed', $externalId."\n", \FILE_APPEND | \LOCK_EX);
    }

    private function mode(): string
    {
        $file = \dirname(__DIR__).'/mode';

        return is_file($file) ? trim((string) file_get_contents($file)) : 'empty';
    }
}
