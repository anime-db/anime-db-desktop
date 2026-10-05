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
 * anime/_info.html.twig and anime/_names.html.twig for every field at least one active filler plugin
 * supports, plus the same button in anime/_media.html.twig (cover) and anime/_gallery.html.twig
 * (images). Same HTMX partial-swap shape as AnimeEditableController (issue #103) - every action
 * here re-renders and swaps the one fragment the field lives in (see renderFillFields()), success or
 * failure alike, so the notice always lands next to the button that triggered it.
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
        requirements: [
            'id' => '\d+',
            'field' => 'alternativeNames|genres|themes|demographic|studios|durationMinutes|episodesCount|countries|cover|images',
        ],
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
            FillResult::DateRangeRejected => 'anime_detail.error_fill_date_range_rejected',
        };

        return $this->renderFillFields($anime, $field, $error);
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
     * Re-renders the one fragment the field lives in, which is also the target of the field's own
     * form (see the fill_button macro in anime/_fill_button.html.twig): the alternative titles block,
     * the cover, the gallery, or the "Information" block for every other field. The notice is
     * rendered inside that same fragment, so it lands next to the button that triggered it
     * (issues #507, #913).
     */
    private function renderFillFields(Anime $anime, string $field, ?string $error): Response
    {
        $template = match ($field) {
            'alternativeNames' => 'anime/_names.html.twig',
            'cover' => 'anime/_media.html.twig',
            'images' => 'anime/_gallery.html.twig',
            default => 'anime/_info.html.twig',
        };

        return new Response($this->twig->render($template, [
            'anime' => $this->viewFactory->serialize($anime),
            'fillable_fields' => $this->fillableFieldsPresenter->build(),
            'fill_error' => $error,
        ]));
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
