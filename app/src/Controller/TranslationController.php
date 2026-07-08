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

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Serves the messages catalogue as JSON so JS can fetch translations instead of keeping its own
 * copy of the dictionary (issue #87) — the JS/PHP drift this replaces already happened once in
 * anime-list.js (issue #82 context).
 */
final class TranslationController
{
    /**
     * @param list<string> $locales same app.locales container parameter LocaleSubscriber
     *                              negotiates against (issue #84)
     */
    public function __construct(
        private readonly array $locales,
        private readonly TranslatorBagInterface $translator,
    ) {
    }

    #[Route(
        '/translations/{locale}.json',
        name: 'translation_catalogue',
        methods: ['GET'],
        requirements: ['locale' => '[a-zA-Z]{2}'],
    )]
    public function __invoke(string $locale): JsonResponse
    {
        if (!\in_array($locale, $this->locales, true)) {
            throw new NotFoundHttpException('Unknown locale.');
        }

        return new JsonResponse($this->translator->getCatalogue($locale)->all('messages'));
    }
}
