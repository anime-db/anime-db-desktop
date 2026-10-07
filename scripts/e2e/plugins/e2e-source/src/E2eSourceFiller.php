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

/**
 * Offline filler for the E2E scenarios. What it answers is chosen by the `mode` file next to the
 * manifest, read on every call, so a scenario can switch it while the server keeps running:
 *
 *  - `empty`  (default) — nothing is found, the host shows its "no match" notice;
 *  - `images` — one record whose gallery holds a single frame (the file is placed in the media
 *               directory beforehand, so the host never has to download it).
 */
final class E2eSourceFiller implements FillerInterface
{
    public const string FRAME_URL = 'https://frames.invalid/e2e-frame.webp';

    public function find(string $name, ?callable $onHeartbeat = null): array
    {
        if ($this->mode() !== 'images') {
            return [];
        }

        return [new SearchByPluginCandidate('e2e-source', $name, 'e2e-1')];
    }

    public function findById(string $externalId): ?PluginAnimeData
    {
        if ($this->mode() !== 'images') {
            return null;
        }

        return new PluginAnimeData(title: 'E2E', images: [self::FRAME_URL]);
    }

    public function resolveExternalId(array $urls): ?string
    {
        return null;
    }

    public function getFillableFields(): array
    {
        return ['cover', 'images'];
    }

    private function mode(): string
    {
        $file = \dirname(__DIR__).'/mode';

        return is_file($file) ? trim((string) file_get_contents($file)) : 'empty';
    }
}
