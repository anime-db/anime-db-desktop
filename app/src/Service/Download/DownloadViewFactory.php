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

namespace App\Service\Download;

use App\Entity\Download;

/**
 * Turns Download rows into the plain array anime/_downloads.html.twig renders (issue #857) — shared
 * by AnimeController (full page load) and DownloadUnlinkController (HTMX fragment swap after
 * "Unlink"), the same split AnimeViewFactory/AnimeEditableController use for the anime card itself.
 */
final class DownloadViewFactory
{
    /**
     * @param list<Download> $downloads
     *
     * @return list<array{id: int, info_hash: string, status: string, version: int}>
     */
    public function serializeList(array $downloads): array
    {
        return array_map(
            static fn (Download $download): array => [
                'id' => $download->id ?? throw new \LogicException('Download must be persisted before it can be rendered.'),
                'info_hash' => $download->getInfoHash(),
                'status' => $download->getStatus()->value,
                'version' => $download->getVersion(),
            ],
            $downloads,
        );
    }
}
