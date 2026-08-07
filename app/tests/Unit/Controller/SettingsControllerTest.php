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

namespace App\Tests\Unit\Controller;

use App\Controller\SettingsController;
use App\Doctrine\Type\RatingType;
use App\Doctrine\Type\UnixTimestampType;
use App\Entity\Enum\WatchStatus;
use App\Entity\MovieAnime;
use App\Service\AppSettingsProvider;
use App\Service\Search\AnimeReindexService;
use App\Service\Search\AnimeSearchIndexer;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\CommunicationException;
use PHPUnit\Framework\TestCase;
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
    ): SettingsController {
        if ($csrfTokenManager === null) {
            $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
            $csrfTokenManager->method('isTokenValid')->willReturn(true);
        }

        return new SettingsController(
            ['en', 'ru'],
            new AppSettingsProvider($this->configPath),
            $csrfTokenManager,
            $twig ?? $this->createStub(Environment::class),
            $reindexService ?? $this->createReindexService($this->createStub(Client::class)),
        );
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
            ])
            ->willReturn('<html></html>');

        $controller = $this->createController(twig: $twig);
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
