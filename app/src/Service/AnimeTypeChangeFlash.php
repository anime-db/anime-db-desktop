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

use App\Entity\Enum\AnimeType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reports the {@see AnimeTypeChangeOutcome} to the user as a flash message, the same text wherever the
 * change was started: the entry card or the "requires attention" page. base.html.twig renders the
 * messages.
 */
final class AnimeTypeChangeFlash
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function add(Request $request, AnimeTypeChangeOutcome $outcome, AnimeType $targetType): void
    {
        [$type, $text] = match ($outcome) {
            AnimeTypeChangeOutcome::Changed => ['success', $this->translator->trans('anime_type_change.flash_changed', ['%type%' => $this->translator->trans('anime_type.'.$targetType->value)])],
            AnimeTypeChangeOutcome::SyncRunning => ['danger', $this->translator->trans('anime_type_change.flash_sync_running')],
            AnimeTypeChangeOutcome::LossNotConfirmed => ['danger', $this->translator->trans('anime_type_change.flash_not_confirmed')],
        };

        $this->addMessage($request, $type, $text);
    }

    /** The change was refused by the domain: the entry has this type already, or the result is forbidden. */
    public function addInvalid(Request $request): void
    {
        $this->addMessage($request, 'danger', $this->translator->trans('anime_type_change.flash_invalid'));
    }

    private function addMessage(Request $request, string $type, string $text): void
    {
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);

        $session->getFlashBag()->add($type, ['text' => $text, 'link_url' => null, 'link_label' => null]);
    }
}
