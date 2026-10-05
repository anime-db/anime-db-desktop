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

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reports the {@see AnimeDeleteOutcome} to the user as a flash message (issue #916), the same text
 * wherever the deletion was started: the entry page or the "requires attention" page. base.html.twig
 * renders the messages. A message is an array of text, link_url and link_label (the last two
 * null when there is no link).
 */
final class AnimeDeleteFlash
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function add(Request $request, AnimeDeleteOutcome $outcome, string $title): void
    {
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);

        [$type, $message] = match ($outcome) {
            AnimeDeleteOutcome::Deleted => ['success', $this->message($this->translator->trans('anime_delete.flash_deleted', ['%title%' => $title]))],
            AnimeDeleteOutcome::PendingDownloads => ['danger', $this->message(
                $this->translator->trans('anime_delete.flash_pending_downloads', ['%title%' => $title]),
                $this->urlGenerator->generate('downloads_index'),
                $this->translator->trans('anime_delete.downloads_link'),
            )],
            AnimeDeleteOutcome::SyncRunning => ['danger', $this->message($this->translator->trans('anime_delete.flash_sync_running'))],
        };

        $session->getFlashBag()->add($type, $message);
    }

    /** @return array{text: string, link_url: ?string, link_label: ?string} */
    private function message(string $text, ?string $linkUrl = null, ?string $linkLabel = null): array
    {
        return ['text' => $text, 'link_url' => $linkUrl, 'link_label' => $linkLabel];
    }
}
