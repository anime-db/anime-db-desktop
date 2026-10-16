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
use App\Service\Plugin\Filler\DescriptionRecalculationPlanner;
use App\Service\Plugin\Filler\DescriptionSourceCandidates;
use App\Service\Plugin\Filler\SaturationQueue;
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
 * The recalculation of the catalog's descriptions (issue #1070) starts only from "Apply to the
 * catalog", and only when a priority has been saved.
 */
final class DescriptionSourcesController
{
    public function __construct(
        private readonly DescriptionSourceCandidates $candidates,
        private readonly AppSettingsProvider $settings,
        private readonly DescriptionRecalculationPlanner $planner,
        private readonly SaturationQueue $queue,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/settings/description-sources', name: 'settings_description_sources_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render($this->candidates->list(), false);
    }

    #[Route('/settings/description-sources/apply', name: 'settings_description_sources_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('settings_description_sources_apply', (string) $request->request->get('_token', '')))) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }

        $queued = null;
        if ($this->settings->getDescriptionSourcePriority() !== []) {
            $queued = 0;
            foreach ($this->planner->eligibleAnimeIds() as $animeId) {
                $this->queue->enqueue($animeId, bulk: true, descriptionsOnly: true);
                ++$queued;
            }
        }

        return $this->render($this->candidates->list(), false, $queued);
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

        // Previously ranked ids that are not candidates right now (e.g. a disabled filler without stored
        // descriptions) keep their rank, so re-enabling the plugin does not demote it below everyone.
        foreach ($this->settings->getDescriptionSourcePriority() as $id) {
            if (!isset($known[$id]) && !\in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        $this->settings->setDescriptionSourcePriority($order);

        return $this->render($this->candidates->list(), true);
    }

    /** @param list<array{id: string, name: string}> $items */
    private function render(array $items, bool $saved, ?int $queued = null): Response
    {
        return new Response($this->twig->render('settings/description_sources/index.html.twig', [
            'items' => $items,
            'saved' => $saved,
            'queued' => $queued,
            // Null while no priority is saved: there is nothing to recalculate, and no estimate to run.
            'apply_count' => $this->settings->getDescriptionSourcePriority() === [] ? null : \count($this->planner->eligibleAnimeIds()),
        ]));
    }
}
