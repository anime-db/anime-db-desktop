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

namespace App\Controller;

use App\Entity\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Shown right after a Storage is created (issue #179, Таск 2 шаг 5): asks whether to run a
 * scan now. "Запустить" submits to the very same StorageController::scan() a regular scan
 * button uses (issue #136), so it dispatches the same ScanStorageMessage and lands on the
 * usual /storage progress screen. "Пропустить" is a plain link back to home_index — the
 * storage is created but not scanned, so the catalog stays empty and the onboarding banner
 * (HomeController) will offer this step again on the next visit.
 */
final class StorageScanPromptController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    #[Route('/storage/{id}/scan-prompt', name: 'storage_scan_prompt', methods: ['GET'])]
    public function prompt(Storage $storage): Response
    {
        return new Response($this->twig->render('storage/scan_prompt.html.twig', [
            'storage' => $storage,
        ]));
    }
}
