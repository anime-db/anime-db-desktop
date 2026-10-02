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

use AnimeDb\PluginContracts\Catalog\AnimeFilesChangedEvent;
use AnimeDb\PluginContracts\Catalog\FilesChangeReason;
use AnimeDb\PluginContracts\Filler\PluginAnimeData;
use AnimeDb\PluginContracts\Model\AnimeId;
use App\Entity\Anime;
use App\Entity\NameNormalizer;
use App\Entity\ValueObject\Exception\InvalidPluginIdException;
use App\Entity\ValueObject\PluginId;
use App\Repository\AnimeRepository;
use App\Service\Plugin\Exception\AnimeAlreadyLinkedToDifferentExternalIdException;
use App\Service\Plugin\Exception\ExternalIdAlreadyClaimedException;
use App\Service\Plugin\Filler\BulkFillerService;
use App\Service\Plugin\Filler\CachedFillerLookup;
use App\Service\Plugin\Filler\FillResult;
use App\Service\Plugin\FillerAvailabilityPresenter;
use App\Service\Plugin\FillerRegistry;
use App\Service\Plugin\InstalledPluginsRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/**
 * "Search in plugins" screen (issue #833): interactive search across every active
 * {@see \AnimeDb\PluginContracts\Filler\FillerInterface} plugin, with a cached-findById preview and
 * add-to-catalog, for a title the user has not found on disk yet — the one path into the catalog
 * other than a storage scan or the bare /anime/new form.
 *
 * Each active plugin is queried by its own HTMX request ({@see self::group()}), fired in parallel
 * by the page itself (see anime/search_plugins/_results.html.twig), rather than through
 * {@see \App\Service\Storage\Search\SearchByPluginChain}: that chain stops at the first plugin with
 * a non-empty result and also admits "pure" search plugins with no findById() to preview. A
 * plugin's find() throwing or timing out is caught here, per group, so one misbehaving plugin
 * never takes the others down with it.
 *
 * The preview ({@see self::preview()}) and the add action ({@see self::add()}) share the same
 * {@see CachedFillerLookup} a storage-scan confirm already uses (issue #832): opening a preview
 * and then adding that same candidate never calls the plugin's findById() a second time.
 */
final class AnimeSearchPluginsController
{
    public function __construct(
        private readonly FillerRegistry $fillerRegistry,
        private readonly InstalledPluginsRegistry $installedPlugins,
        private readonly FillerAvailabilityPresenter $fillerAvailability,
        private readonly CachedFillerLookup $lookup,
        private readonly AnimeRepository $animeRepository,
        private readonly BulkFillerService $bulkFiller,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/anime/search-plugins', name: 'anime_search_plugins', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $hasActiveFiller = $this->fillerAvailability->hasActiveFiller();

        return new Response($this->twig->render('anime/search_plugins/index.html.twig', [
            'query' => trim((string) $request->query->get('q', '')),
            'hasActiveFiller' => $hasActiveFiller,
            'noFillerState' => $hasActiveFiller ? null : $this->fillerAvailability->describeUnavailable(),
            'error' => $this->resolveError($request),
        ]));
    }

    /**
     * Turns the `?error=` code a redirect (issue #848, points 3-4) carries back onto this screen
     * into a translation key plus, for `conflict_claimed`, a link to the catalog record that
     * already owns the external id — `owner_id` is only ever present for that one error code, and
     * a record deleted between the redirect and this request simply degrades to no link.
     *
     * @return array{messageKey: string, messageParams: array<string, string>, link: array{url: string, labelKey: string}|null}|null
     */
    private function resolveError(Request $request): ?array
    {
        return match ((string) $request->query->get('error', '')) {
            'fill_not_found' => ['messageKey' => 'search_plugins.error_fill_not_found', 'messageParams' => [], 'link' => null],
            'conflict_claimed' => $this->resolveConflictClaimedError($request),
            'conflict_linked_other' => $this->resolveConflictLinkedOtherError($request),
            default => null,
        };
    }

    /** @return array{messageKey: string, messageParams: array<string, string>, link: array{url: string, labelKey: string}|null} */
    private function resolveConflictClaimedError(Request $request): array
    {
        $ownerId = (int) $request->query->get('owner_id', 0);
        $owner = $ownerId > 0 ? $this->entityManager->find(Anime::class, $ownerId) : null;

        return [
            'messageKey' => 'search_plugins.error_conflict_claimed',
            'messageParams' => [],
            'link' => $owner instanceof Anime
                ? ['url' => $this->urlGenerator->generate('anime_show', ['id' => $owner->id]), 'labelKey' => 'search_plugins.error_conflict_claimed_link']
                : null,
        ];
    }

    /** @return array{messageKey: string, messageParams: array<string, string>, link: null} */
    private function resolveConflictLinkedOtherError(Request $request): array
    {
        $rawPluginId = (string) $request->query->get('plugin_id', '');
        $pluginName = $rawPluginId;
        try {
            $pluginName = $this->installedPlugins->get(new PluginId($rawPluginId))?->manifest->name ?? $rawPluginId;
        } catch (InvalidPluginIdException) {
        }

        return [
            'messageKey' => 'search_plugins.error_conflict_linked_other',
            'messageParams' => ['%plugin%' => $pluginName],
            'link' => null,
        ];
    }

    /**
     * Renders one placeholder per active plugin (issue #833, point 2), each carrying its own
     * `hx-get` to {@see self::group()} with `hx-trigger="load"` — the same "fire every slot's own
     * request right after the shell appears" shape as plugin/_widget_slots.html.twig. A blank
     * query renders no placeholders at all rather than firing a round trip per plugin for nothing.
     */
    #[Route('/anime/search-plugins/results', name: 'anime_search_plugins_results', methods: ['GET'])]
    public function results(Request $request): Response
    {
        return new Response($this->twig->render('anime/search_plugins/_results.html.twig', [
            'query' => trim((string) $request->query->get('q', '')),
            'pluginIds' => array_keys($this->fillerRegistry->findAllActive()),
        ]));
    }

    /**
     * One plugin's own group of candidates. $plugin->find() is deliberately not routed through
     * {@see \App\Service\Storage\Search\SearchByPluginChain} (see class docblock) and its
     * exception is caught right here, so a timeout or a thrown exception from this one plugin
     * degrades to "could not fetch results" for its own group only, never a 500 for the page.
     */
    #[Route(
        '/anime/search-plugins/results/{pluginId}',
        name: 'anime_search_plugins_group',
        requirements: ['pluginId' => '[a-z0-9]+(-[a-z0-9]+)+'],
        methods: ['GET'],
    )]
    public function group(string $pluginId, Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));

        try {
            $id = new PluginId($pluginId);
        } catch (InvalidPluginIdException) {
            throw new NotFoundHttpException(\sprintf('Unknown plugin "%s".', $pluginId));
        }

        $pluginName = $this->installedPlugins->get($id)?->manifest->name ?? $pluginId;
        $filler = $this->fillerRegistry->findByPluginId($id);

        if ($filler === null || $query === '') {
            return $this->renderGroup($pluginId, $pluginName, 'empty', [], $query);
        }

        try {
            $candidates = $filler->find($query);
        } catch (\Throwable $exception) {
            $this->logger->warning('Plugin find() failed during a search-plugins group request.', [
                'pluginId' => $pluginId,
                'exception' => $exception,
            ]);

            return $this->renderGroup($pluginId, $pluginName, 'error', [], $query);
        }

        return $this->renderGroup($pluginId, $pluginName, $candidates === [] ? 'empty' : 'results', array_map(
            static fn ($candidate): array => ['name' => $candidate->getName(), 'externalId' => $candidate->getExternalId()],
            $candidates,
        ), $query);
    }

    /**
     * One cached {@see CachedFillerLookup::findById()} call, plus the two "might already be in
     * the catalog" checks (issue #833, points 5-6): by (pluginId, externalId) via
     * {@see AnimeRepository::resolve()}, and — only when that misses — by normalized name via
     * {@see AnimeRepository::findCandidatesByNormalizedNameExcludingPlugin()}.
     */
    #[Route('/anime/search-plugins/preview', name: 'anime_search_plugins_preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $rawPluginId = (string) $request->query->get('plugin_id', '');
        $externalId = (string) $request->query->get('external_id', '');
        $name = (string) $request->query->get('name', '');
        $query = trim((string) $request->query->get('q', ''));

        try {
            $pluginId = new PluginId($rawPluginId);
        } catch (InvalidPluginIdException) {
            return $this->renderPreviewError();
        }

        if ($externalId === '') {
            return $this->renderPreviewError();
        }

        $filler = $this->fillerRegistry->findByPluginId($pluginId);
        if ($filler === null) {
            return $this->renderPreviewError();
        }

        try {
            $data = $this->lookup->findById($filler, $pluginId, $externalId);
        } catch (\Throwable $exception) {
            $this->logger->warning('Plugin findById() failed during a search-plugins preview request.', [
                'pluginId' => $rawPluginId,
                'exception' => $exception,
            ]);

            return $this->renderPreviewError();
        }

        if ($data === null) {
            return $this->renderPreviewError();
        }

        $context = [
            'data' => $this->serializePreview($data, $request->getLocale()),
            'pluginId' => $rawPluginId,
            'externalId' => $externalId,
            'name' => $name,
            'query' => $query,
        ];

        $existing = $this->animeRepository->resolve($pluginId, $externalId);
        if ($existing !== null) {
            return new Response($this->twig->render('anime/search_plugins/_preview.html.twig', [
                ...$context,
                'state' => 'already_in_catalog',
                'existingAnimeId' => $existing->id,
            ]));
        }

        $possibleMatch = $name !== ''
            ? ($this->animeRepository->findCandidatesByNormalizedNameExcludingPlugin(NameNormalizer::normalize($name), $pluginId)[0] ?? null)
            : null;

        return new Response($this->twig->render('anime/search_plugins/_preview.html.twig', [
            ...$context,
            'state' => $possibleMatch !== null ? 'possible_match' : 'new',
            'possibleMatch' => $possibleMatch,
        ]));
    }

    /**
     * "Add to catalog" (issue #833, point 4) and "Create new" (point 6, the possible-match
     * block's second button, same action — it deliberately ignores the suggested match). Both go
     * through {@see BulkFillerService::findOrCreateFromPlugin()} with
     * downloadCoverSynchronously: true, same as a single storage-scan candidate confirm (issue
     * #832), so a cover is worth waiting for when there is exactly one record being added. Reuses
     * the preview's own cached findById() result - no second network call.
     */
    #[Route('/anime/search-plugins/add', name: 'anime_search_plugins_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->assertValidCsrfToken('anime_search_plugins_add', $request);

        $pluginId = $this->requirePluginId((string) $request->request->get('plugin_id', ''));
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException('"name" is required.');
        }
        $externalId = (string) $request->request->get('external_id', '');

        $result = $this->bulkFiller->findOrCreateFromPlugin($pluginId, $externalId, $name, downloadCoverSynchronously: true);
        $this->entityManager->flush();

        if (!$result->wasFound) {
            $animeId = $result->anime->id ?? throw new \LogicException('Anime must have an id once it has been flushed.');
            $this->eventDispatcher->dispatch(new AnimeFilesChangedEvent(new AnimeId($animeId), FilesChangeReason::Created));
        }

        return new RedirectResponse($this->urlGenerator->generate('anime_show', ['id' => $result->anime->id]));
    }

    /**
     * "Fill in the existing record" (issue #833, point 6's first button): links $externalId to
     * an already-catalogued record the user picked out of the possible-match suggestion and fills
     * only its still-empty fields, via {@see BulkFillerService::fillExistingFromPlugin()} (issue
     * #832, point 10) — no separate "fill existing" logic lives in this controller. A
     * {@see FillResult::NotFound} result (the plugin's findById() threw, returned nothing, or
     * filling got disabled between the preview and this click) sends the user back to the search
     * screen with an error instead of a silent redirect to a record that was never actually
     * updated — the same "turn a non-Applied result into user-visible feedback" contract
     * {@see AnimeFillController} already follows for its own single-field fill.
     */
    #[Route(
        '/anime/search-plugins/fill-existing/{animeId}',
        name: 'anime_search_plugins_fill_existing',
        requirements: ['animeId' => '\d+'],
        methods: ['POST'],
    )]
    public function fillExisting(int $animeId, Request $request): Response
    {
        $this->assertValidCsrfToken('anime_search_plugins_fill_existing_'.$animeId, $request);

        $anime = $this->entityManager->find(Anime::class, $animeId);
        if (!$anime instanceof Anime) {
            throw new NotFoundHttpException(\sprintf('Anime #%d not found.', $animeId));
        }

        $pluginId = $this->requirePluginId((string) $request->request->get('plugin_id', ''));
        $externalId = (string) $request->request->get('external_id', '');
        if ($externalId === '') {
            throw new BadRequestHttpException('"external_id" is required.');
        }
        $query = trim((string) $request->request->get('q', ''));

        try {
            $result = $this->bulkFiller->fillExistingFromPlugin($anime, $pluginId, $externalId);
        } catch (ExternalIdAlreadyClaimedException $exception) {
            return new RedirectResponse($this->urlGenerator->generate('anime_search_plugins', [
                'error' => 'conflict_claimed',
                'owner_id' => $exception->animeId,
                'q' => $query,
            ]));
        } catch (AnimeAlreadyLinkedToDifferentExternalIdException) {
            return new RedirectResponse($this->urlGenerator->generate('anime_search_plugins', [
                'error' => 'conflict_linked_other',
                'plugin_id' => (string) $pluginId,
                'q' => $query,
            ]));
        }

        if ($result === FillResult::NotFound) {
            return new RedirectResponse($this->urlGenerator->generate('anime_search_plugins', ['error' => 'fill_not_found', 'q' => $query]));
        }

        return new RedirectResponse($this->urlGenerator->generate('anime_show', ['id' => $animeId]));
    }

    private function requirePluginId(string $raw): PluginId
    {
        try {
            return new PluginId($raw);
        } catch (InvalidPluginIdException $exception) {
            throw new BadRequestHttpException('Invalid plugin id.', $exception);
        }
    }

    /** @param list<array{name: string, externalId: string}> $candidates */
    private function renderGroup(string $pluginId, string $pluginName, string $state, array $candidates, string $query): Response
    {
        return new Response($this->twig->render('anime/search_plugins/_group.html.twig', [
            'pluginId' => $pluginId,
            'pluginName' => $pluginName,
            'state' => $state,
            'candidates' => $candidates,
            'query' => $query,
        ]));
    }

    private function renderPreviewError(): Response
    {
        return new Response($this->twig->render('anime/search_plugins/_preview.html.twig', ['state' => 'error']));
    }

    /** @return array<string, mixed> */
    private function serializePreview(PluginAnimeData $data, string $locale): array
    {
        $descriptions = $data->descriptions ?? [];
        $description = $descriptions[$locale] ?? reset($descriptions) ?: null;

        return [
            'title' => $data->title,
            'cover' => $data->cover,
            'alternativeNames' => array_map(
                static fn ($name): array => ['name' => $name->name, 'locale' => $name->locale],
                $data->alternativeNames ?? [],
            ),
            'type' => $data->type?->value,
            'datePremiere' => $data->datePremiere?->format('Y-m-d'),
            'dateEnd' => $data->dateEnd?->format('Y-m-d'),
            'episodesCount' => $data->episodesCount,
            'genres' => array_map(static fn ($genre): string => $genre->value, $data->genres ?? []),
            'description' => $description,
        ];
    }

    private function assertValidCsrfToken(string $tokenId, Request $request): void
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
