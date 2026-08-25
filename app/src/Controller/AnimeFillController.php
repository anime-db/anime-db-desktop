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

use App\Entity\Anime;
use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Service\AnimeViewFactory;
use App\Service\Plugin\Filler\FieldFillerService;
use App\Service\Plugin\Filler\FillableFieldsPresenter;
use App\Service\Plugin\Filler\FillResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Point fill-in of a single card field from an explicitly chosen plugin (issue #234, extended to
 * cover/images by issue #507): the "fill from source" button/dropdown rendered by
 * anime/_fill_fields.html.twig for every field at least one active filler plugin supports, plus
 * the same button in anime/_media.html.twig (cover) and anime/_gallery.html.twig (images). Same
 * HTMX partial-swap shape as AnimeEditableController (issue #103) - every action here re-renders
 * and swaps the fields fragment, and for cover/images additionally sends the corresponding
 * partial back as an out-of-band swap (see renderFillFields()) so the visible cover/gallery
 * update in the same response instead of silently staying stale until the next full page load.
 *
 * The resolve/merge itself lives in FieldFillerService; this controller only wires the HTTP
 * request to it and turns a non-Applied {@see FillResult} into the same fragment with an inline
 * notice instead of an error page, per the issue's UX.
 */
final class AnimeFillController
{
    public function __construct(
        private readonly FieldFillerService $fieldFiller,
        private readonly FillableFieldsPresenter $fillableFieldsPresenter,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly AnimeViewFactory $viewFactory,
        private readonly Environment $twig,
    ) {
    }

    #[Route(
        '/anime/{id}/fill/{field}',
        name: 'anime_fill_field',
        requirements: ['field' => 'alternativeNames|genres|themes|demographic|studios|durationMinutes|episodesCount|countries|cover|images'],
        methods: ['POST'],
    )]
    public function fill(Anime $anime, string $field, Request $request): Response
    {
        $this->assertValidCsrfToken('anime_fill_'.$field.'_'.$anime->id, $request);

        $result = $this->tryFill($anime, $field, (string) $request->request->get('plugin_id', ''));

        $error = match ($result) {
            FillResult::Applied => null,
            FillResult::NotFound => 'anime_detail.error_fill_not_found',
            FillResult::ImageRejected => 'anime_detail.error_fill_image_rejected',
        };

        return $this->renderFillFields($anime, $field, $result, $error);
    }

    private function tryFill(Anime $anime, string $field, string $rawPluginId): FillResult
    {
        if ($rawPluginId === '') {
            return FillResult::NotFound;
        }

        try {
            $pluginId = new PluginId($rawPluginId);
        } catch (InvalidPluginIdException) {
            return FillResult::NotFound;
        }

        return $this->fieldFiller->fill($anime, $pluginId, $field);
    }

    /**
     * The fields fragment is always re-rendered and is always the response's primary content
     * (its id matches the form's hx-target). When $field is 'cover' or 'images' and $result is
     * Applied, the matching anime/_media.html.twig or anime/_gallery.html.twig partial is
     * appended after it - both carry hx-swap-oob="outerHTML" on their root element, so HTMX
     * swaps them into place by id anywhere on the page regardless of the response's declared
     * target, without touching anything else on the card (issue #507).
     */
    private function renderFillFields(Anime $anime, string $field, FillResult $result, ?string $error): Response
    {
        $context = [
            'anime' => $this->viewFactory->serialize($anime),
            'fillable_fields' => $this->fillableFieldsPresenter->build(),
        ];

        $html = $this->twig->render('anime/_fill_fields.html.twig', [...$context, 'fill_error' => $error]);

        if ($result === FillResult::Applied && $field === 'cover') {
            $html .= $this->twig->render('anime/_media.html.twig', $context);
        } elseif ($result === FillResult::Applied && $field === 'images') {
            $html .= $this->twig->render('anime/_gallery.html.twig', $context);
        }

        return new Response($html);
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
