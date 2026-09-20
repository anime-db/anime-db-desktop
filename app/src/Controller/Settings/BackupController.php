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

namespace App\Controller\Settings;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Renders /settings/backup (issue #657). The page itself only lays out the markup
 * app/public/js/backup.js drives — starting the export, showing its `export.progress`/
 * `export.done`/`export.failed` events and cancelling it all go through
 * window.animeDb.catalogExport*() (native/catalog-export/index.js) straight from the renderer,
 * the same IPC route as window.animeDb.pickFolder() for the destination folder, not an HTTP
 * endpoint on this controller — the export itself runs as a separate `bin/console
 * app:catalog:export` process (native/supervisor/php-command.js), so there is nothing for a
 * synchronous HTTP request here to trigger or wait on.
 */
final class BackupController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    #[Route('/settings/backup', name: 'settings_backup_index', methods: ['GET'])]
    public function index(): Response
    {
        return new Response($this->twig->render('settings/backup/index.html.twig'));
    }
}
