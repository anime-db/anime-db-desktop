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

namespace App\Controller;

use App\Entity\Storage;
use App\Repository\StorageRepository;
use App\Service\Storage\StorageAvailabilityService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * The top-nav "Add" menu's "Scan" section (issue #834), loaded lazily over htmx rather than
 * rendered inline with the rest of the menu in base.html.twig: it needs
 * {@see StorageAvailabilityService::unavailableStorageIds()}, an `is_readable()` filesystem check
 * per storage that can be slow on a network path, and the menu is part of every page's header.
 * app/assets/js/nav-add-menu.js fetches this fragment once per page, the first time the menu is
 * opened.
 *
 * Only {@see \App\Entity\Enum\StorageType::isWritable()} storages are listed — `ExternalR` and
 * `Video` scan empty, so they are excluded from both the per-storage items and the "N not
 * connected" count, never just hidden from the former.
 */
final class NavController
{
    public function __construct(
        private readonly StorageRepository $storages,
        private readonly StorageAvailabilityService $storageAvailability,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/nav/add-menu/scan-section', name: 'nav_add_menu_scan_section', methods: ['GET'])]
    public function addMenuScanSection(): Response
    {
        $scannable = array_values(array_filter(
            $this->storages->findAllOrderedByName(),
            static fn (Storage $storage): bool => $storage->getType()->isWritable() && $storage->getPath() !== null,
        ));

        $unavailableIds = $this->storageAvailability->unavailableStorageIds($scannable);
        $connected = array_values(array_filter(
            $scannable,
            static fn (Storage $storage): bool => !\in_array($storage->id, $unavailableIds, true),
        ));

        return new Response($this->twig->render('nav/_add_menu_scan_section.html.twig', [
            'connectedStorages' => $connected,
            'disconnectedCount' => \count($scannable) - \count($connected),
            'hasScannableStorages' => $scannable !== [],
        ]));
    }
}
