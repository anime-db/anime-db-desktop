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

use App\Service\Import\StagedImportService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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
 *
 * The pending-import banner (issue #671) is a plain HTTP GET/POST pair instead, unlike the
 * export/import sections above: reading the staging marker and removing the staging directory
 * are both synchronous filesystem operations with nothing to poll over /ws, so a `Response`
 * with the same PRG shape {@see \App\Controller\SettingsController} uses for its own settings
 * actions is all this needs.
 */
final class BackupController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly StagedImportService $stagedImportService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/settings/backup', name: 'settings_backup_index', methods: ['GET'])]
    public function index(): Response
    {
        return new Response($this->twig->render('settings/backup/index.html.twig', [
            'stagedImport' => $this->stagedImportService->readMarker(),
        ]));
    }

    /**
     * Removes `import-staging/` entirely (acceptance criterion 5) — the next app start then has
     * nothing staged to look at, same as if the archive had never been prepared.
     */
    #[Route('/settings/backup/import/cancel', name: 'settings_backup_import_cancel', methods: ['POST'])]
    public function cancelImport(Request $request): RedirectResponse
    {
        $token = new CsrfToken('settings_backup_import_cancel', (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $this->stagedImportService->cancel();

        return new RedirectResponse($this->urlGenerator->generate('settings_backup_index'), Response::HTTP_SEE_OTHER);
    }
}
