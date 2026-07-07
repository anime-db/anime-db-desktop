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

namespace App\Service;

use App\Entity\Enum\PaginationMode;

/**
 * Reads user-facing app settings from %AppData%/config.json, the same file
 * native/config.js writes appSecret to. Missing file/key/unreadable JSON all fall back to
 * the documented default (infinite scroll, issue #74) rather than failing the request.
 */
final class AppSettingsProvider
{
    public function __construct(private readonly string $configPath)
    {
    }

    public function getPaginationMode(): PaginationMode
    {
        $data = $this->readConfig();
        $mode = $data['paginationMode'] ?? null;

        if (!\is_string($mode)) {
            return PaginationMode::InfiniteScroll;
        }

        return PaginationMode::tryFrom($mode) ?? PaginationMode::InfiniteScroll;
    }

    /** @return array<string, mixed> */
    private function readConfig(): array
    {
        if (!is_file($this->configPath)) {
            return [];
        }

        $contents = file_get_contents($this->configPath);
        if (false === $contents) {
            return [];
        }

        $data = json_decode($contents, true);

        return \is_array($data) ? $data : [];
    }
}
