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

namespace App\Tests\Unit\Controller;

use App\Controller\SettingsController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\PaginationMode;
use App\Entity\Enum\SyncReviewItemKind;
use App\Entity\Enum\ThemePreference;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Entity\SyncReviewItem;
use App\Repository\SyncReviewItemRepository;
use App\Service\AppConfigStore;
use App\Service\AppSettingsProvider;
use App\Service\Plugin\AvailableLocalesProvider;
use App\Service\Plugin\InstalledPluginsRegistry;
use App\Service\Plugin\PluginsConfigStore;
use App\Service\Search\AnimeReindexService;
use App\Service\Search\AnimeSearchIndexer;
use App\Service\Sync\SyncReviewService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\CommunicationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class SettingsControllerTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir().'/anime-config-test-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->configPath, $this->configPath.'.tmp', $this->configPath.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function createController(
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?Environment $twig = null,
        ?AnimeReindexService $reindexService = null,
        ?SyncReviewService $syncReview = null,
        ?UrlGeneratorInterface $urlGenerator = null,
    ): SettingsController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        if ($urlGenerator === null) {
            $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
            $urlGenerator->method('generate')->willReturn('/settings');
        }

        return new SettingsController(
            $this->availableLocalesProvider(['en', 'ru']),
            new AppSettingsProvider(new AppConfigStore($this->configPath)),
            $csrfTokenManager,
            $twig ?? $this->createStub(Environment::class),
            $reindexService ?? $this->createReindexService($this->createStub(Client::class)),
            $syncReview ?? $this->createSyncReview([]),
            $urlGenerator,
        );
    }

    /**
     * @param list<string> $coreLocales
     */
    private function availableLocalesProvider(array $coreLocales): AvailableLocalesProvider
    {
        $registry = new InstalledPluginsRegistry(
            sys_get_temp_dir().'/anime-settings-controller-test-does-not-exist',
            new PluginsConfigStore(sys_get_temp_dir().'/anime-settings-controller-test-plugins.json'),
            new NullLogger(),
        );

        return new AvailableLocalesProvider($registry, $coreLocales);
    }

    /** @param SyncReviewItem[] $unresolved */
    private function createSyncReview(array $unresolved): SyncReviewService
    {
        $repository = $this->createStub(SyncReviewItemRepository::class);
        $repository->method('findAllUnresolvedOrderedByCreatedAt')->willReturn($unresolved);

        return new SyncReviewService($repository);
    }

    /**
     * Same doubling strategy as IndexAnimeMessageHandlerTest: AnimeSearchIndexer (behind
     * AnimeReindexService) is final and talks to a real Meilisearch\Client, so the Client is
     * what gets doubled here.
     */
    private function createReindexService(Client $client): AnimeReindexService
    {
        if (!Type::hasType(UnixTimestampType::NAME)) {
            Type::addType(UnixTimestampType::NAME, UnixTimestampType::class);
        }
        if (!Type::hasType(RatingType::NAME)) {
            Type::addType(RatingType::NAME, RatingType::class);
        }

        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 3).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $anime = new MovieAnime();
        $anime->setTitle('Cowboy Bebop')->setWatchStatus(WatchStatus::Plan);
        $entityManager->persist($anime);
        $entityManager->flush();

        return new AnimeReindexService($entityManager, new AnimeSearchIndexer($client));
    }

    public function testIndexPassesAvailableLocalesAndNullUnavailableLocaleWhenConfigIsEmpty(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'unavailableLocale' => null,
                'reindexStatus' => null,
                'needsCorrectionCount' => 0,
                'themePreference' => ThemePreference::System,
                'paginationMode' => PaginationMode::InfiniteScroll,
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig);
        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexPassesNullUnavailableLocaleWhenSavedLocaleIsAvailable(): void
    {
        file_put_contents($this->configPath, json_encode(['locale' => 'ru']));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'unavailableLocale' => null,
                'reindexStatus' => null,
                'needsCorrectionCount' => 0,
                'themePreference' => ThemePreference::System,
                'paginationMode' => PaginationMode::InfiniteScroll,
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig);
        $controller->index();
    }

    /**
     * Acceptance (issue #558): a saved locale that dropped out of the available list (e.g. a
     * translation plugin got removed, or a safe-mode start disabled it) must be surfaced to the
     * template rather than silently falling back to another locale.
     */
    public function testIndexPassesUnavailableLocaleWhenSavedLocaleIsNotInAvailableList(): void
    {
        file_put_contents($this->configPath, json_encode(['locale' => 'de']));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'unavailableLocale' => 'de',
                'reindexStatus' => null,
                'needsCorrectionCount' => 0,
                'themePreference' => ThemePreference::System,
                'paginationMode' => PaginationMode::InfiniteScroll,
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig);
        $controller->index();
    }

    /**
     * Acceptance (issue #382): the settings link badge counts only NeedsCorrection items — a
     * PotentialDuplicate row unresolved at the same time must not inflate it.
     */
    public function testIndexCountsOnlyUnresolvedNeedsCorrectionItemsForTheBadge(): void
    {
        $needsCorrection = new SyncReviewItem(SyncReviewItemKind::NeedsCorrection, ['anime_id' => 1, 'candidates' => []]);
        $duplicate = new SyncReviewItem(SyncReviewItemKind::PotentialDuplicate, ['anime_ids' => [1, 2]]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'unavailableLocale' => null,
                'reindexStatus' => null,
                'needsCorrectionCount' => 1,
                'themePreference' => ThemePreference::System,
                'paginationMode' => PaginationMode::InfiniteScroll,
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig, syncReview: $this->createSyncReview([$needsCorrection, $duplicate]));
        $controller->index();
    }

    /**
     * Acceptance (issue #558): the switch persists the choice and answers with a PRG redirect
     * (303) to `settings_index`, rather than rendering the page in place — a plain 302 would let
     * the client repeat the POST, defeating the point of PRG here.
     */
    public function testSetLocalePersistsChoiceAndRedirectsWithSeeOther(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())->method('generate')->with('settings_index')->willReturn('/settings');

        $controller = $this->createController(urlGenerator: $urlGenerator);
        $request = Request::create('/settings', 'POST', ['locale' => 'ru', '_token' => 'token']);

        $response = $controller->setLocale($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/settings', $response->getTargetUrl());

        $data = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertSame('ru', $data['locale']);
    }

    public function testSetLocaleRejectsUnknownLocale(): void
    {
        $controller = $this->createController();
        $request = Request::create('/settings', 'POST', ['locale' => 'fr', '_token' => 'token']);

        $this->expectException(BadRequestHttpException::class);
        $controller->setLocale($request);
    }

    public function testSetLocaleRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings', 'POST', ['locale' => 'ru', '_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->setLocale($request);
    }

    /**
     * Acceptance (issue #638): same PRG shape as the locale switch — the choice is persisted
     * before the redirect, not rendered in place.
     */
    public function testSetThemePersistsChoiceAndRedirectsWithSeeOther(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())->method('generate')->with('settings_index')->willReturn('/settings');

        $controller = $this->createController(urlGenerator: $urlGenerator);
        $request = Request::create('/settings/theme', 'POST', ['themePreference' => 'dark', '_token' => 'token']);

        $response = $controller->setTheme($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/settings', $response->getTargetUrl());

        $data = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertSame('dark', $data['themePreference']);
    }

    public function testSetThemeRejectsUnknownValue(): void
    {
        $controller = $this->createController();
        $request = Request::create('/settings/theme', 'POST', ['themePreference' => 'blue', '_token' => 'token']);

        $this->expectException(BadRequestHttpException::class);
        $controller->setTheme($request);
    }

    public function testSetThemeRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings/theme', 'POST', ['themePreference' => 'dark', '_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->setTheme($request);
    }

    /**
     * Acceptance (issue #665): same PRG shape as the theme switch — classic pagination is
     * unreachable until this endpoint persists the choice.
     */
    public function testSetPaginationModePersistsChoiceAndRedirectsWithSeeOther(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())->method('generate')->with('settings_index')->willReturn('/settings');

        $controller = $this->createController(urlGenerator: $urlGenerator);
        $request = Request::create('/settings/pagination-mode', 'POST', ['paginationMode' => 'classic', '_token' => 'token']);

        $response = $controller->setPaginationMode($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/settings', $response->getTargetUrl());

        $data = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertSame('classic', $data['paginationMode']);
    }

    public function testSetPaginationModeRejectsUnknownValue(): void
    {
        $controller = $this->createController();
        $request = Request::create('/settings/pagination-mode', 'POST', ['paginationMode' => 'bogus', '_token' => 'token']);

        $this->expectException(BadRequestHttpException::class);
        $controller->setPaginationMode($request);
    }

    public function testSetPaginationModeRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings/pagination-mode', 'POST', ['paginationMode' => 'classic', '_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->setPaginationMode($request);
    }

    public function testReindexSearchRerendersWithSuccessStatus(): void
    {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('updateSettings')->willReturn(['taskUid' => 1]);
        $index->expects($this->once())->method('deleteAllDocuments')->willReturn(['taskUid' => 3]);
        $index->expects($this->once())->method('addDocuments')->willReturn(['taskUid' => 2]);
        $index->method('waitForTask');

        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(3))->method('index')->with('anime')->willReturn($index);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'unavailableLocale' => null,
                'reindexStatus' => 'success',
                'needsCorrectionCount' => 0,
                'themePreference' => ThemePreference::System,
                'paginationMode' => PaginationMode::InfiniteScroll,
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig, reindexService: $this->createReindexService($client));
        $request = Request::create('/settings/search/reindex', 'POST', ['_token' => 'token']);

        $response = $controller->reindexSearch($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testReindexSearchRerendersWithErrorStatusWhenMeilisearchFails(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('index')->willThrowException(new CommunicationException('connection refused'));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'unavailableLocale' => null,
                'reindexStatus' => 'error',
                'needsCorrectionCount' => 0,
                'themePreference' => ThemePreference::System,
                'paginationMode' => PaginationMode::InfiniteScroll,
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig, reindexService: $this->createReindexService($client));
        $request = Request::create('/settings/search/reindex', 'POST', ['_token' => 'token']);

        $response = $controller->reindexSearch($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testReindexSearchRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = Request::create('/settings/search/reindex', 'POST', ['_token' => 'bad']);

        $this->expectException(BadRequestHttpException::class);
        $controller->reindexSearch($request);
    }

    /** @param array<string, mixed> $payload */
    private function filterSectionsRequest(array $payload): Request
    {
        return Request::create('/settings/filter-sections', 'POST', [], [], [], [], (string) json_encode($payload));
    }

    /**
     * Acceptance (issue #820): a bare 204, not the PRG redirect every other setter above uses —
     * this endpoint is fired in the background by anime-list-filters.js on every section-toggle
     * click, with no page reload to redirect back to.
     */
    public function testSetFilterSectionsPersistsCollapsedSectionsAndReturnsNoContent(): void
    {
        $controller = $this->createController();
        $request = $this->filterSectionsRequest(['token' => 'token', 'collapsed' => ['genres', 'studios']]);

        $response = $controller->setFilterSections($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());

        $data = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertSame(['genres', 'studios'], $data['collapsedFilterSections']);
    }

    public function testSetFilterSectionsRejectsInvalidCsrfToken(): void
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $controller = $this->createController(csrfTokenManager: $csrf);
        $request = $this->filterSectionsRequest(['token' => 'bad', 'collapsed' => ['genres']]);

        $this->expectException(BadRequestHttpException::class);
        $controller->setFilterSections($request);
    }

    public function testSetFilterSectionsRejectsNonArrayBody(): void
    {
        $controller = $this->createController();
        $request = Request::create('/settings/filter-sections', 'POST', [], [], [], [], '"not an object"');

        $this->expectException(BadRequestHttpException::class);
        $controller->setFilterSections($request);
    }

    public function testSetFilterSectionsRejectsNonArrayCollapsed(): void
    {
        $controller = $this->createController();
        $request = $this->filterSectionsRequest(['token' => 'token', 'collapsed' => 'genres']);

        $this->expectException(BadRequestHttpException::class);
        $controller->setFilterSections($request);
    }
}
