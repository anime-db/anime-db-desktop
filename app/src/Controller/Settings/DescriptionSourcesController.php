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

use App\Service\AppSettingsProvider;
use App\Service\Plugin\Filler\DescriptionSourceCandidates;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * The "description sources" settings screen (issue #1069): one ordered list of plugins for all
 * locales. The up/down buttons only re-render the list in the new order; "Save" is the sole write,
 * and it stores the setting and nothing else - no message goes to the bus, no saturation starts.
 */
final class DescriptionSourcesController
{
    public function __construct(
        private readonly DescriptionSourceCandidates $candidates,
        private readonly AppSettingsProvider $settings,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/settings/description-sources', name: 'settings_description_sources_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render($this->candidates->list(), false);
    }

    #[Route('/settings/description-sources', name: 'settings_description_sources_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('settings_description_sources', (string) $request->request->get('_token', '')))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $known = [];
        foreach ($this->candidates->list() as $item) {
            $known[$item['id']] = $item;
        }

        $order = [];
        foreach ($request->request->all('order') as $id) {
            if (\is_string($id) && isset($known[$id]) && !\in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        // Candidates missing from the submitted order keep their relative place after the listed ones.
        foreach (array_keys($known) as $id) {
            if (!\in_array((string) $id, $order, true)) {
                $order[] = (string) $id;
            }
        }

        $move = (string) $request->request->get('move', '');
        if ($move !== '') {
            [$id, $direction] = array_pad(explode(':', $move, 2), 2, '');
            $index = array_search($id, $order, true);
            $target = $index === false ? false : ($direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : false));
            if ($index !== false && $target !== false && isset($order[$target])) {
                [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
            }

            return $this->render(array_values(array_map(static fn (string $id): array => $known[$id], $order)), false);
        }

        $this->settings->setDescriptionSourcePriority($order);

        return $this->render($this->candidates->list(), true);
    }

    /** @param list<array{id: string, name: string}> $items */
    private function render(array $items, bool $saved): Response
    {
        return new Response($this->twig->render('settings/description_sources/index.html.twig', [
            'items' => $items,
            'saved' => $saved,
        ]));
    }
}
