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
use App\Entity\Enum\SyncReviewItemKind;
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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
    ): SettingsController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        return new SettingsController(
            $this->availableLocalesProvider(['en', 'ru']),
            new AppSettingsProvider(new AppConfigStore($this->configPath)),
            $csrfTokenManager,
            $twig ?? $this->createStub(Environment::class),
            $reindexService ?? $this->createReindexService($this->createStub(Client::class)),
            $syncReview ?? $this->createSyncReview([]),
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

    public function testIndexPassesAvailableLocalesAndFallsBackToFirstOneWhenConfigIsEmpty(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'currentLocale' => 'en',
                'reindexStatus' => null,
                'needsCorrectionCount' => 0,
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig);
        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexPassesCurrentLocaleFromConfig(): void
    {
        file_put_contents($this->configPath, json_encode(['locale' => 'ru']));

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'currentLocale' => 'ru',
                'reindexStatus' => null,
                'needsCorrectionCount' => 0,
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
                'currentLocale' => 'en',
                'reindexStatus' => null,
                'needsCorrectionCount' => 1,
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig, syncReview: $this->createSyncReview([$needsCorrection, $duplicate]));
        $controller->index();
    }

    public function testSetLocalePersistsChoiceAndRerendersWithoutRedirecting(): void
    {
        $controller = $this->createController();
        $request = Request::create('/settings', 'POST', ['locale' => 'ru', '_token' => 'token']);

        $response = $controller->setLocale($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertInstanceOf(Response::class, $response);

        $data = json_decode((string) file_get_contents($this->configPath), true);
        $this->assertSame('ru', $data['locale']);
    }

    /**
     * Acceptance (issue #538): native/accept-language.js sends the Accept-Language header built
     * from config.json as it stood *before* this POST persisted the new locale, so without this,
     * the request itself would still carry the previous locale even though the response body
     * already reflects the new one via `currentLocale`.
     */
    public function testSetLocaleSynchronizesTheRequestLocaleWithThePersistedChoice(): void
    {
        $controller = $this->createController();
        $request = Request::create('/settings', 'POST', ['locale' => 'ru', '_token' => 'token']);
        $request->setLocale('en');

        $controller->setLocale($request);

        $this->assertSame('ru', $request->getLocale());
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

    public function testReindexSearchRerendersWithSuccessStatus(): void
    {
        $index = $this->createMock(Indexes::class);
        $index->expects($this->once())->method('updateSettings')->willReturn(['taskUid' => 1]);
        $index->expects($this->once())->method('addDocuments')->willReturn(['taskUid' => 2]);
        $index->method('waitForTask');

        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))->method('index')->with('anime')->willReturn($index);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with('settings/index.html.twig', [
                'availableLocales' => ['en', 'ru'],
                'currentLocale' => 'en',
                'reindexStatus' => 'success',
                'needsCorrectionCount' => 0,
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
                'currentLocale' => 'en',
                'reindexStatus' => 'error',
                'needsCorrectionCount' => 0,
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
}
